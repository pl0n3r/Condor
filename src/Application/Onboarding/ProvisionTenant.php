<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ProvisionTenant
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Prepara tenant, entidad legal y sede dentro de la transacción del caller.
     *
     * El caller decide qué identidad inicial se crea y realiza el flush final,
     * de modo que onboarding e invitaciones de plataforma compartan el mismo
     * núcleo sin transacciones parciales.
     */
    public function prepare(ProvisionTenantInput $input): ProvisionTenantResult
    {
        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            throw new ValidationFailedException($input, $violations);
        }

        $slug = strtolower(trim($input->tenantSlug));
        if (
            $this->entityManager
                ->getRepository(Tenant::class)
                ->findOneBy(['slug' => $slug]) instanceof Tenant
        ) {
            throw new DomainException(
                'Ya existe una empresa con ese identificador.',
            );
        }

        $tenant = new Tenant($input->tenantName, $slug);
        $legalEntity = new LegalEntity(
            $tenant,
            $input->legalName,
            $input->nit,
            true,
        );
        $branch = new Branch(
            $tenant,
            $input->branchName,
            'principal',
            $legalEntity,
            true,
        );

        foreach ([$tenant, $legalEntity, $branch] as $entity) {
            $this->entityManager->persist($entity);
        }

        return new ProvisionTenantResult(
            $tenant,
            $legalEntity,
            $branch,
        );
    }
}
