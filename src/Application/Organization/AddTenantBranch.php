<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Commercial\BillableUsage;
use App\Application\Commercial\ExecutionContext;
use App\Application\Commercial\TenantUsageReservation;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Alta de una sede facturable posterior al bootstrap. Idempotente por slug.
 * Nunca crea la sede inicial (default): esa pertenece al onboarding.
 */
final readonly class AddTenantBranch
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantUsageReservation $reservation,
        private BillableUsage $usage,
    ) {
    }

    public function add(ExecutionContext $context, string $name, string $slug): Branch
    {
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException('El nombre de la sede es obligatorio y admite máximo 160 caracteres.');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 120) {
            throw new DomainException('El slug de la sede no es válido.');
        }

        return $this->reservation->run(
            $context,
            function (Tenant $tenant) use ($context, $name, $slug): Branch {
                $existing = $this->entityManager->getRepository(Branch::class)
                    ->findOneBy(['tenant' => $tenant, 'slug' => $slug]);
                if ($existing instanceof Branch) {
                    if ($existing->isDefault()) {
                        throw new DomainException('La sede inicial no es facturable ni se re-crea.');
                    }

                    return $existing;
                }

                $this->usage->assertCanAdd(
                    $context,
                    BillableUsage::LOCATIONS,
                    $this->usage->locations($tenant->id()),
                );

                $branch = new Branch($tenant, $name, $slug);
                $this->entityManager->persist($branch);
                $this->entityManager->flush();

                return $branch;
            },
        );
    }
}
