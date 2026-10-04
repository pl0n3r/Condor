<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Commercial\EntitlementSnapshot;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class MaterialService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function create(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        string $code,
        string $name,
        UnitOfMeasure $unitOfMeasure,
    ): Material {
        self::assertEntitledTenant($tenant, $entitlements);
        $canonicalCode = Material::canonicalCode($code);
        $this->assertCodeAvailable($tenant, $canonicalCode);

        $material = new Material(
            $tenant,
            $canonicalCode,
            $name,
            $unitOfMeasure,
        );
        $this->entityManager->persist($material);

        return $material;
    }

    public function update(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        Material $material,
        string $code,
        string $name,
        UnitOfMeasure $unitOfMeasure,
    ): Material {
        self::assertEntitledTenant($tenant, $entitlements);
        self::assertOwnedMaterial($tenant, $material);

        $canonicalCode = Material::canonicalCode($code);
        $this->assertCodeAvailable($tenant, $canonicalCode, $material);
        $material->update($canonicalCode, $name, $unitOfMeasure);

        return $material;
    }

    public function deactivate(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        Material $material,
    ): Material {
        self::assertEntitledTenant($tenant, $entitlements);
        self::assertOwnedMaterial($tenant, $material);
        $material->deactivate();

        return $material;
    }

    private function assertCodeAvailable(
        Tenant $tenant,
        string $code,
        ?Material $current = null,
    ): void {
        $existing = $this->entityManager
            ->getRepository(Material::class)
            ->findOneBy([
                'tenant' => $tenant,
                'code' => $code,
            ]);

        if (
            $existing instanceof Material
            && (!$current instanceof Material || $existing->id() !== $current->id())
        ) {
            throw new DomainException(
                'Ya existe un material con ese código en el tenant.',
            );
        }
    }

    private static function assertEntitledTenant(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
    ): void {
        if ($entitlements->tenantId() !== $tenant->id()) {
            throw new DomainException(
                'El entitlement pertenece a otro tenant.',
            );
        }

        try {
            $productionLite = $entitlements->addOn('production-lite');
        } catch (DomainException $exception) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
                0,
                $exception,
            );
        }

        if ($productionLite !== true) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
            );
        }
    }

    private static function assertOwnedMaterial(
        Tenant $tenant,
        Material $material,
    ): void {
        if ($material->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'El material pertenece a otro tenant.',
            );
        }
    }
}
