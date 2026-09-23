<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Inventory\InventoryService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class InventoryController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryService $inventory,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory',
        name: 'api_inventory_snapshot',
        methods: ['GET'],
    )]
    public function snapshot(string $branchId): JsonResponse
    {
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.view',
        );
        $legalEntity = $this->legalEntity($branch);

        $criteria = [
            'tenant' => $tenant,
            'legalEntity' => $legalEntity,
            'active' => true,
        ];
        if (!$this->authorization->isOwner($membership)) {
            $criteria['branch'] = $branch;
        }

        $sources = array_values(array_filter(
            $this->entityManager
                ->getRepository(InventorySource::class)
                ->findBy($criteria, ['name' => 'ASC']),
            static fn (mixed $source): bool => $source instanceof InventorySource,
        ));

        $variants = array_values(array_filter(
            $this->entityManager
                ->getRepository(ProductVariant::class)
                ->findBy(['tenant' => $tenant, 'active' => true], ['name' => 'ASC']),
            static fn (mixed $variant): bool =>
                $variant instanceof ProductVariant && $variant->product()->isActive(),
        ));

        $balances = $sources === []
            ? []
            : $this->entityManager
                ->getRepository(InventoryBalance::class)
                ->findBy(
                    [
                        'tenant' => $tenant,
                        'legalEntity' => $legalEntity,
                        'source' => $sources,
                    ],
                    ['updatedAt' => 'DESC'],
                );

        $movements = $sources === []
            ? []
            : $this->entityManager
                ->getRepository(InventoryMovement::class)
                ->findBy(
                    [
                        'tenant' => $tenant,
                        'legalEntity' => $legalEntity,
                        'source' => $sources,
                    ],
                    ['createdAt' => 'DESC'],
                    50,
                );

        return $this->json([
            'legal_entity' => self::legalEntityPayload($legalEntity),
            'branch' => [
                'id' => $branch->id(),
                'name' => $branch->name(),
            ],
            'sources' => array_map(self::sourcePayload(...), $sources),
            'variants' => array_map(
                static fn (ProductVariant $variant): array => [
                    'id' => $variant->id(),
                    'sku' => $variant->sku(),
                    'name' => $variant->name(),
                    'product_name' => $variant->product()->name(),
                ],
                $variants,
            ),
            'balances' => array_values(array_map(
                static fn (mixed $balance): array => self::balancePayload(
                    self::inventoryBalance($balance),
                ),
                $balances,
            )),
            'movements' => array_values(array_map(
                static fn (mixed $movement): array => self::movementPayload(
                    self::inventoryMovement($movement),
                ),
                $movements,
            )),
            'can_manage_logical_sources' => $this->authorization->isOwner(
                $membership,
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/sources',
        name: 'api_inventory_source_create',
        methods: ['POST'],
    )]
    public function createSource(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.create',
        );
        $legalEntity = $this->legalEntity($branch);
        $payload = $this->payload($request, ['name', 'slug', 'type']);
        $type = $this->requiredString($payload, 'type');

        if (
            $type === InventorySource::TYPE_LOGICAL
            && !$this->authorization->isOwner($membership)
        ) {
            throw new AccessDeniedHttpException(
                'Solo el propietario puede crear fuentes lógicas sin sede.',
            );
        }

        $source = $this->domain(
            fn (): InventorySource => new InventorySource(
                $tenant,
                $legalEntity,
                $this->requiredString($payload, 'name'),
                $this->requiredString($payload, 'slug'),
                $type,
                $type === InventorySource::TYPE_BRANCH ? $branch : null,
            ),
        );
        $this->entityManager->persist($source);
        $this->audit(
            $tenant,
            $user,
            'inventory_source.created',
            InventorySource::class,
            $source->id(),
            [
                'branch_id' => $branch->id(),
                'legal_entity_id' => $legalEntity->id(),
                'type' => $source->type(),
            ],
        );
        $this->flushUnique(
            'Ya existe una fuente de inventario con ese identificador o sede.',
        );

        return $this->json(
            ['source' => self::sourcePayload($source)],
            Response::HTTP_CREATED,
        );
    }


    #[Route(
        '/api/v1/branches/{branchId}/inventory/sources/{sourceId}',
        name: 'api_inventory_source_update',
        methods: ['PATCH'],
    )]
    public function updateSource(
        string $branchId,
        string $sourceId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $legalEntity = $this->legalEntity($branch);
        $source = $this->source($sourceId, $tenant, $legalEntity);
        $this->requireSourcePermission(
            $user,
            $tenant,
            $membership,
            $source,
            'inventory.update',
        );
        $payload = $this->payload($request, ['name', 'slug']);

        $this->domain(function () use ($source, $payload): null {
            $source->update(
                $this->requiredString($payload, 'name'),
                $this->requiredString($payload, 'slug'),
            );

            return null;
        });
        $this->audit(
            $tenant,
            $user,
            'inventory_source.updated',
            InventorySource::class,
            $source->id(),
            [
                'branch_id' => $branch->id(),
                'legal_entity_id' => $legalEntity->id(),
            ],
        );
        $this->flushUnique(
            'Ya existe una fuente de inventario con ese identificador.',
        );

        return $this->json(['source' => self::sourcePayload($source)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/sources/{sourceId}',
        name: 'api_inventory_source_delete',
        methods: ['DELETE'],
    )]
    public function deleteSource(
        string $branchId,
        string $sourceId,
        Request $request,
    ): Response {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.delete',
        );
        $legalEntity = $this->legalEntity($branch);
        $source = $this->source($sourceId, $tenant, $legalEntity);
        $this->requireSourcePermission(
            $user,
            $tenant,
            $membership,
            $source,
            'inventory.delete',
        );

        if ($this->sourceHasNonZeroStock($source)) {
            throw new ConflictHttpException(
                'No se puede desactivar una fuente que conserva stock.',
            );
        }

        $source->deactivate();
        $this->audit(
            $tenant,
            $user,
            'inventory_source.deactivated',
            InventorySource::class,
            $source->id(),
            [
                'branch_id' => $branch->id(),
                'legal_entity_id' => $legalEntity->id(),
            ],
        );
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/adjustments',
        name: 'api_inventory_adjustment_create',
        methods: ['POST'],
    )]
    public function adjust(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $legalEntity = $this->legalEntity($branch);
        $payload = $this->payload(
            $request,
            ['source_id', 'variant_id', 'delta', 'reason', 'idempotency_key'],
        );
        $source = $this->source(
            $this->requiredString($payload, 'source_id'),
            $tenant,
            $legalEntity,
        );
        $this->requireSourcePermission(
            $user,
            $tenant,
            $membership,
            $source,
            'inventory.update',
        );
        $variant = $this->variant(
            $this->requiredString($payload, 'variant_id'),
            $tenant,
        );
        $reason = trim($this->requiredString($payload, 'reason'));
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 240) {
            throw new UnprocessableEntityHttpException(
                'El motivo es obligatorio y admite máximo 240 caracteres.',
            );
        }

        $movement = $this->domain(
            fn (): InventoryMovement => $this->inventory->adjust(
                $tenant,
                $source,
                $variant,
                $this->requiredInt($payload, 'delta'),
                $user->id(),
                $this->requiredString($payload, 'idempotency_key'),
                [
                    'reason' => $reason,
                    'branch_id' => $branch->id(),
                    'legal_entity_id' => $legalEntity->id(),
                ],
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'inventory.adjusted',
            InventoryMovement::class,
            $movement->id(),
            [
                'branch_id' => $branch->id(),
                'legal_entity_id' => $legalEntity->id(),
                'source_id' => $source->id(),
                'variant_id' => $variant->id(),
                'delta' => $movement->delta(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['movement' => self::movementPayload($movement)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/transfers',
        name: 'api_inventory_transfer_create',
        methods: ['POST'],
    )]
    public function transfer(
        string $branchId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $legalEntity = $this->legalEntity($branch);
        $payload = $this->payload(
            $request,
            [
                'source_from_id',
                'source_to_id',
                'variant_id',
                'quantity',
                'idempotency_key',
            ],
        );

        $sourceFrom = $this->source(
            $this->requiredString($payload, 'source_from_id'),
            $tenant,
            $legalEntity,
        );
        $sourceTo = $this->source(
            $this->requiredString($payload, 'source_to_id'),
            $tenant,
            $legalEntity,
        );
        $this->requireSourcePermission(
            $user,
            $tenant,
            $membership,
            $sourceFrom,
            'inventory.update',
        );
        $this->requireSourcePermission(
            $user,
            $tenant,
            $membership,
            $sourceTo,
            'inventory.update',
        );
        $variant = $this->variant(
            $this->requiredString($payload, 'variant_id'),
            $tenant,
        );

        $transfer = $this->domain(
            fn (): InventoryTransfer => $this->inventory->transfer(
                $tenant,
                $variant,
                $sourceFrom,
                $sourceTo,
                $this->requiredInt($payload, 'quantity'),
                $user->id(),
                $this->requiredString($payload, 'idempotency_key'),
            ),
        );
        $this->audit(
            $tenant,
            $user,
            'inventory.transferred',
            InventoryTransfer::class,
            $transfer->id(),
            [
                'branch_id' => $branch->id(),
                'legal_entity_id' => $legalEntity->id(),
                'source_from_id' => $sourceFrom->id(),
                'source_to_id' => $sourceTo->id(),
                'variant_id' => $variant->id(),
                'quantity' => $transfer->quantity(),
            ],
        );
        $this->entityManager->flush();

        return $this->json(
            ['transfer' => self::transferPayload($transfer)],
            Response::HTTP_CREATED,
        );
    }

    private function legalEntity(Branch $branch): LegalEntity
    {
        $legalEntity = $branch->legalEntity();
        if (!$legalEntity instanceof LegalEntity) {
            throw new ConflictHttpException(
                'La sede debe tener una entidad legal antes de operar inventario.',
            );
        }

        return $legalEntity;
    }

    private function source(
        string $sourceId,
        Tenant $tenant,
        LegalEntity $legalEntity,
    ): InventorySource {
        $source = $this->entityManager
            ->getRepository(InventorySource::class)
            ->findOneBy([
                'id' => $sourceId,
                'tenant' => $tenant,
                'legalEntity' => $legalEntity,
                'active' => true,
            ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException(
                'Fuente de inventario no encontrada.',
            );
        }

        return $source;
    }

    private function variant(
        string $variantId,
        Tenant $tenant,
    ): ProductVariant {
        $variant = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findOneBy([
                'id' => $variantId,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (
            !$variant instanceof ProductVariant
            || !$variant->product()->isActive()
        ) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }

        return $variant;
    }

    private function requireSourcePermission(
        User $user,
        Tenant $tenant,
        Membership $membership,
        InventorySource $source,
        string $permission,
    ): void {
        if ($this->authorization->isOwner($membership)) {
            return;
        }

        $sourceBranch = $source->branch();
        if (!$sourceBranch instanceof Branch) {
            throw new AccessDeniedHttpException(
                'No tienes acceso a esta fuente lógica.',
            );
        }

        try {
            $this->authorization->require(
                $user,
                $tenant,
                $sourceBranch,
                $permission,
            );
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser texto.', $field),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser entero.', $field),
            );
        }

        return $value;
    }

    private function sourceHasNonZeroStock(
        InventorySource $source,
    ): bool {
        $balances = $this->entityManager
            ->getRepository(InventoryBalance::class)
            ->findBy(['source' => $source]);

        foreach ($balances as $balance) {
            if (
                $balance instanceof InventoryBalance
                && $balance->quantity() !== 0
            ) {
                return true;
            }
        }

        return false;
    }

    private function flushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }

    /** @return array<string, mixed> */
    private static function legalEntityPayload(LegalEntity $legalEntity): array
    {
        return [
            'id' => $legalEntity->id(),
            'name' => $legalEntity->legalName(),
        ];
    }

    /** @return array<string, mixed> */
    private static function sourcePayload(InventorySource $source): array
    {
        return [
            'id' => $source->id(),
            'name' => $source->name(),
            'slug' => $source->slug(),
            'type' => $source->type(),
            'branch_id' => $source->branch()?->id(),
            'legal_entity_id' => $source->legalEntity()->id(),
        ];
    }

    /** @return array<string, mixed> */
    private static function balancePayload(InventoryBalance $balance): array
    {
        $variant = $balance->variant();

        return [
            'id' => $balance->id(),
            'source_id' => $balance->source()->id(),
            'legal_entity_id' => $balance->legalEntity()->id(),
            'variant' => [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name(),
                'product_name' => $variant->product()->name(),
            ],
            'quantity' => $balance->quantity(),
            'version' => $balance->version(),
        ];
    }

    /** @return array<string, mixed> */
    private static function movementPayload(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id(),
            'source_id' => $movement->source()->id(),
            'legal_entity_id' => $movement->legalEntity()->id(),
            'variant_id' => $movement->variant()->id(),
            'type' => $movement->type(),
            'delta' => $movement->delta(),
            'balance_after' => $movement->balanceAfter(),
            'transfer_id' => $movement->transfer()?->id(),
            'context' => $movement->context(),
            'created_at' => $movement->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function transferPayload(InventoryTransfer $transfer): array
    {
        return [
            'id' => $transfer->id(),
            'legal_entity_id' => $transfer->legalEntity()->id(),
            'source_from_id' => $transfer->sourceFrom()->id(),
            'source_to_id' => $transfer->sourceTo()->id(),
            'variant_id' => $transfer->variant()->id(),
            'quantity' => $transfer->quantity(),
            'status' => $transfer->status(),
            'created_at' => $transfer->createdAt()->format(DATE_ATOM),
        ];
    }

    private static function inventoryBalance(mixed $value): InventoryBalance
    {
        if (!$value instanceof InventoryBalance) {
            throw new \LogicException('Saldo de inventario inválido.');
        }

        return $value;
    }

    private static function inventoryMovement(mixed $value): InventoryMovement
    {
        if (!$value instanceof InventoryMovement) {
            throw new \LogicException('Movimiento de inventario inválido.');
        }

        return $value;
    }
}
