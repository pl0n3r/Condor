<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Application\Commercial\EntitlementSnapshot;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class BillOfMaterialsService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<array{
     *     material: Material,
     *     quantity: string,
     *     unit: UnitOfMeasure
     * }> $components
     */
    public function createVersion(
        Tenant $tenant,
        EntitlementSnapshot $entitlements,
        ProductVariant $variant,
        array $components,
    ): BillOfMaterials {
        self::assertEntitledTenant($tenant, $entitlements);
        self::assertOwnedVariant($tenant, $variant);

        return $this->entityManager->wrapInTransaction(
            function () use ($tenant, $variant, $components): BillOfMaterials {
                $this->entityManager->lock(
                    $variant,
                    LockMode::PESSIMISTIC_WRITE,
                );

                $repository = $this->entityManager
                    ->getRepository(BillOfMaterials::class);
                $latest = $repository->findOneBy(
                    ['tenant' => $tenant, 'variant' => $variant],
                    ['version' => 'DESC'],
                );
                $active = $repository->findBy(
                    [
                        'tenant' => $tenant,
                        'variant' => $variant,
                        'active' => true,
                    ],
                    ['version' => 'DESC'],
                    2,
                );

                if (count($active) > 1) {
                    throw new DomainException(
                        'Existen múltiples BOM activas para la misma variante.',
                    );
                }

                $version = $latest instanceof BillOfMaterials
                    ? $latest->version() + 1
                    : 1;

                $current = $active[0] ?? null;
                if ($current instanceof BillOfMaterials) {
                    $current->retire();
                }

                $billOfMaterials = new BillOfMaterials(
                    $tenant,
                    $variant,
                    $version,
                    $components,
                );
                $this->entityManager->persist($billOfMaterials);

                return $billOfMaterials;
            },
        );
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
            $enabled = $entitlements->addOn('production-lite');
        } catch (DomainException $exception) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
                0,
                $exception,
            );
        }

        if ($enabled !== true) {
            throw new DomainException(
                'Producción Lite no está habilitada para este tenant.',
            );
        }
    }

    private static function assertOwnedVariant(
        Tenant $tenant,
        ProductVariant $variant,
    ): void {
        if ($variant->tenant()->id() !== $tenant->id()) {
            throw new DomainException(
                'La variante pertenece a otro tenant.',
            );
        }
        if (!$variant->isActive()) {
            throw new DomainException(
                'La variante debe estar activa para crear una BOM.',
            );
        }
    }
}
