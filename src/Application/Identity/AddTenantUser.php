<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Commercial\BillableUsage;
use App\Application\Commercial\ExecutionContext;
use App\Application\Commercial\TenantUsageReservation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Alta de un asiento facturable posterior al bootstrap. Idempotente por correo:
 * repetir la llamada devuelve la membresía existente sin consumir otro asiento.
 */
final readonly class AddTenantUser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantUsageReservation $reservation,
        private BillableUsage $usage,
    ) {
    }

    public function add(ExecutionContext $context, string $email, string $displayName, string $roleKey): Membership
    {
        $email = strtolower(trim($email));
        $displayName = trim($displayName);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('El correo no es válido.');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
            throw new DomainException('El nombre es obligatorio y admite máximo 160 caracteres.');
        }
        if ($roleKey === Membership::ROLE_OWNER || trim($roleKey) === '') {
            throw new DomainException('El rol del asiento no puede ser OWNER.');
        }

        return $this->reservation->run(
            $context,
            function (Tenant $tenant) use ($context, $email, $displayName, $roleKey): Membership {
                $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($user instanceof User) {
                    $existing = $this->entityManager->getRepository(Membership::class)
                        ->findOneBy(['tenant' => $tenant, 'user' => $user]);
                    if ($existing instanceof Membership && $existing->roleKey() === $roleKey) {
                        return $existing;
                    }

                    throw new DomainException('Ya existe una cuenta con ese correo.');
                }

                $this->usage->assertCanAdd(
                    $context,
                    BillableUsage::SEATS,
                    $this->usage->seats($tenant->id()),
                );

                $user = new User($email, $displayName);
                $user->deactivate();
                $membership = new Membership($tenant, $user, $roleKey);
                $this->entityManager->persist($user);
                $this->entityManager->persist($membership);
                $this->entityManager->flush();

                return $membership;
            },
        );
    }
}
