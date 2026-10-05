<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Production\MaterialInventoryService;
use App\Application\Production\MaterialService;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\MaterialInventoryMovement;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProductionMaterialsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly MaterialService $materials,
        private readonly MaterialInventoryService $materialInventory,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/materials',
        name: 'api_production_materials_snapshot',
        methods: ['GET'],
    )]
    public function snapshot(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );
        $this->productionEntitlements($tenant);
        $source = $this->branchSource($tenant, $branch);

        $materials = $this->entityManager
            ->getRepository(Material::class)
            ->findBy(['tenant' => $tenant], ['code' => 'ASC']);
        $balances = $this->entityManager
            ->getRepository(MaterialInventoryBalance::class)
            ->findBy([
                'tenant' => $tenant,
                'source' => $source,
            ]);
        $movements = $this->entityManager
            ->getRepository(MaterialInventoryMovement::class)
            ->findBy(
                [
                    'tenant' => $tenant,
                    'source' => $source,
                ],
                ['createdAt' => 'DESC'],
                50,
            );

        $balanceByMaterial = [];
        foreach ($balances as $balance) {
            $balanceByMaterial[$balance->material()->id()] = $balance;
        }

        return $this->json([
            'branch' => [
                'id' => $branch->id(),
                'name' => $branch->name(),
            ],
            'source' => self::sourcePayload($source),
            'units' => UnitOfMeasure::keys(),
            'materials' => array_map(
                self::materialPayload(...),
                $materials,
            ),
            'balances' => array_values(array_map(
                self::balancePayload(...),
                $balanceByMaterial,
            )),
            'movements' => array_map(
                self::movementPayload(...),
                $movements,
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/materials',
        name: 'api_production_material_create',
        methods: ['POST'],
    )]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.create',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $this->branchSource($tenant, $branch);
        $payload = $this->payload($request, ['code', 'name', 'unit']);

        $material = $this->domain(fn (): Material => $this->materials->create(
            $tenant,
            $entitlements,
            $this->requiredString($payload, 'code', 64),
            $this->requiredString($payload, 'name', 160),
            UnitOfMeasure::from($this->requiredString($payload, 'unit', 16)),
        ));
        $this->audit(
            $tenant,
            $user,
            'production_material.created',
            Material::class,
            $material->id(),
            ['branch_id' => $branch->id()],
        );
        $this->flushUnique('Ya existe un material con ese código en el tenant.');

        return $this->json(
            ['material' => self::materialPayload($material)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/materials/{materialId}',
        name: 'api_production_material_update',
        methods: ['PATCH'],
    )]
    public function update(
        string $branchId,
        string $materialId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $this->branchSource($tenant, $branch);
        $material = $this->material($tenant, $materialId);
        $payload = $this->payload($request, ['code', 'name', 'unit']);

        $this->domain(fn (): Material => $this->materials->update(
            $tenant,
            $entitlements,
            $material,
            $this->requiredString($payload, 'code', 64),
            $this->requiredString($payload, 'name', 160),
            UnitOfMeasure::from($this->requiredString($payload, 'unit', 16)),
        ));
        $this->audit(
            $tenant,
            $user,
            'production_material.updated',
            Material::class,
            $material->id(),
            ['branch_id' => $branch->id()],
        );
        $this->flushUnique('Ya existe un material con ese código en el tenant.');

        return $this->json(['material' => self::materialPayload($material)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/materials/{materialId}',
        name: 'api_production_material_delete',
        methods: ['DELETE'],
    )]
    public function deactivate(
        string $branchId,
        string $materialId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.delete',
        );
        $entitlements = $this->productionEntitlements($tenant);
        $this->branchSource($tenant, $branch);
        $material = $this->material($tenant, $materialId);

        $this->domain(fn (): Material => $this->materials->deactivate(
            $tenant,
            $entitlements,
            $material,
        ));
        $this->audit(
            $tenant,
            $user,
            'production_material.deactivated',
            Material::class,
            $material->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return $this->json(['material' => self::materialPayload($material)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/production/materials/{materialId}/adjustments',
        name: 'api_production_material_adjustment',
        methods: ['POST'],
    )]
    public function adjust(
        string $branchId,
        string $materialId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $this->productionEntitlements($tenant);
        $source = $this->branchSource($tenant, $branch);
        $material = $this->material($tenant, $materialId);
        $payload = $this->payload(
            $request,
            ['delta', 'reason', 'idempotency_key'],
        );
        $delta = $this->requiredString($payload, 'delta', 64);
        $reason = $this->requiredString($payload, 'reason', 240);
        $idempotencyKey = $this->requiredString(
            $payload,
            'idempotency_key',
            120,
        );

        $existing = $this->entityManager
            ->getRepository(MaterialInventoryMovement::class)
            ->findOneBy([
                'tenant' => $tenant,
                'idempotencyKey' => $idempotencyKey,
            ]);
        $created = !$existing instanceof MaterialInventoryMovement;

        $movement = $this->domain(
            fn (): MaterialInventoryMovement =>
                $this->materialInventory->adjust(
                    $tenant,
                    $source,
                    $material,
                    $delta,
                    $user->id(),
                    $idempotencyKey,
                    [
                        'branch_id' => $branch->id(),
                        'reason' => $reason,
                    ],
                ),
        );

        if ($created) {
            $this->audit(
                $tenant,
                $user,
                'production_material.adjusted',
                MaterialInventoryMovement::class,
                $movement->id(),
                [
                    'branch_id' => $branch->id(),
                    'source_id' => $source->id(),
                    'material_id' => $material->id(),
                ],
            );
            $this->entityManager->flush();
        }

        return $this->json(
            ['movement' => self::movementPayload($movement)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    private function productionEntitlements(Tenant $tenant): EntitlementSnapshot
    {
        return $this->domain(function () use ($tenant): EntitlementSnapshot {
            $context = $this->entitlementContexts->forTenant(
                $tenant->id(),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            $snapshot = $this->entitlementResolver->resolve($context);

            if ($snapshot->addOn('production-lite') !== true) {
                throw new DomainException(
                    'Producción Lite no está habilitada para este tenant.',
                );
            }

            return $snapshot;
        });
    }

    private function branchSource(
        Tenant $tenant,
        Branch $branch,
    ): InventorySource {
        $source = $this->entityManager
            ->getRepository(InventorySource::class)
            ->findOneBy([
                'tenant' => $tenant,
                'branch' => $branch,
                'type' => InventorySource::TYPE_BRANCH,
                'active' => true,
            ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException(
                'Fuente de inventario activa para la sede no encontrada.',
            );
        }

        return $source;
    }

    private function material(Tenant $tenant, string $materialId): Material
    {
        $material = $this->entityManager
            ->getRepository(Material::class)
            ->findOneBy([
                'id' => $materialId,
                'tenant' => $tenant,
            ]);
        if (!$material instanceof Material) {
            throw new NotFoundHttpException('Material no encontrado.');
        }

        return $material;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(
        array $payload,
        string $field,
        int $maxLength,
    ): string {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                'El campo '.$field.' es obligatorio.',
            );
        }

        $value = trim($value);
        if (
            $value === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            throw new UnprocessableEntityHttpException(
                'El campo '.$field.' no es válido.',
            );
        }

        return $value;
    }

    private function flushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }

    /** @return array<string,mixed> */
    private static function sourcePayload(InventorySource $source): array
    {
        return [
            'id' => $source->id(),
            'name' => $source->name(),
            'slug' => $source->slug(),
        ];
    }

    /** @return array<string,mixed> */
    private static function materialPayload(Material $material): array
    {
        return [
            'id' => $material->id(),
            'code' => $material->code(),
            'name' => $material->name(),
            'unit' => $material->unitOfMeasure()->key(),
            'active' => $material->isActive(),
        ];
    }

    /** @return array<string,mixed> */
    private static function balancePayload(
        MaterialInventoryBalance $balance,
    ): array {
        return [
            'id' => $balance->id(),
            'source_id' => $balance->source()->id(),
            'material_id' => $balance->material()->id(),
            'quantity' => $balance->quantity(),
            'version' => $balance->version(),
        ];
    }

    /** @return array<string,mixed> */
    private static function movementPayload(
        MaterialInventoryMovement $movement,
    ): array {
        return [
            'id' => $movement->id(),
            'source_id' => $movement->source()->id(),
            'material_id' => $movement->material()->id(),
            'type' => $movement->type(),
            'delta' => $movement->delta(),
            'balance_after' => $movement->balanceAfter(),
            'idempotency_key' => $movement->idempotencyKey(),
            'context' => $movement->context(),
            'created_at' => $movement->createdAt()->format(DATE_ATOM),
        ];
    }
}
