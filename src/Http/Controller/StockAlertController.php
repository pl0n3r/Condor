<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Notification\NotificationPreferenceService;
use App\Application\Notification\StockAlertService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\NotificationCatalog;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Branch-scoped in-app stock alerts and append-only stock-minimum settings.
 *
 * No schema change is needed for build-ahead: threshold revisions are durable,
 * tenant-scoped AuditEvent records. The inventory writer must still supply the
 * last confirmed minimum to StockAlertService::onMovement in its transaction;
 * this controller never synthesizes a movement or issues an external delivery.
 */
final class StockAlertController extends AbstractController
{
    use BranchApiSupport;

    private const MINIMUM_ACTION = 'inventory.stock_minimum.set';

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationPreferenceService $preferences,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/inventory/stock-alerts', name: 'api_stock_alerts_list', methods: ['GET'])]
    public function alerts(string $branchId): JsonResponse
    {
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $source = $this->branchSource($tenant, $branch);
        // Filter by authenticated tenant AND branch before limiting results.
        $ids = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
            SELECT d.id FROM condor_notification_delivery d
            WHERE d.user_id = :user AND d.event_key = :event
              AND d.channel_key = :channel
              AND d.status IN (:pending, :delivered)
              AND JSON_UNQUOTE(JSON_EXTRACT(d.payload, '$.tenant_id')) = :tenant
              AND JSON_UNQUOTE(JSON_EXTRACT(d.payload, '$.source_id')) = :source
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT 201
            SQL,
            [
                'user' => $user->id(), 'event' => NotificationCatalog::EVENT_STOCK_LOW,
                'channel' => NotificationCatalog::CHANNEL_IN_APP,
                'pending' => NotificationDelivery::STATUS_PENDING,
                'delivered' => NotificationDelivery::STATUS_DELIVERED,
                'tenant' => $tenant->id(), 'source' => $source->id(),
            ],
        )->fetchFirstColumn();
        if (count($ids) > 200) {
            throw new ServiceUnavailableHttpException(null, 'Demasiadas alertas para una página.');
        }
        $candidateDeliveries = $ids === [] ? [] : $this->entityManager
            ->getRepository(NotificationDelivery::class)->findBy(['id' => $ids]);
        $service = StockAlertService::fromPreferences($this->preferences);
        $alerts = [];
        foreach ($service->visibleInbox($membership, $candidateDeliveries) as $delivery) {
            $data = $delivery->payload();
            if (($data['source_id'] ?? null) !== $source->id()
                || !in_array($delivery->status(), [
                    NotificationDelivery::STATUS_PENDING,
                    NotificationDelivery::STATUS_DELIVERED,
                ], true)
                || !is_string($data['variant_id'] ?? null)
                || !is_string($data['movement_id'] ?? null)
                || !is_int($data['minimum'] ?? null)
                || !is_int($data['quantity'] ?? null)) {
                continue;
            }
            $alerts[] = [
                'id' => $delivery->id(), 'variant_id' => $data['variant_id'],
                'source_id' => $source->id(), 'movement_id' => $data['movement_id'],
                'minimum' => $data['minimum'], 'quantity' => $data['quantity'],
                'status' => $delivery->status(),
            ];
        }

        return $this->json(['source_id' => $source->id(), 'alerts' => $alerts]);
    }

    #[Route('/api/v1/branches/{branchId}/inventory/stock-alerts/minimums', name: 'api_stock_minimums_list', methods: ['GET'])]
    public function minimums(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.view');
        $source = $this->branchSource($tenant, $branch);
        // Read the latest value of every variant in THIS branch regardless
        // of how many immutable audit revisions exist in the tenant.
        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
            SELECT latest.variant_id, latest.context FROM (
                SELECT a.entity_id AS variant_id, a.context,
                       ROW_NUMBER() OVER (
                           PARTITION BY a.entity_id
                           ORDER BY a.created_at DESC, a.id DESC
                       ) AS revision
                FROM condor_audit_event a
                WHERE a.tenant_id = :tenant AND a.action = :action
                  AND a.entity_type = :type
                  AND JSON_UNQUOTE(JSON_EXTRACT(a.context, '$.source_id')) = :source
                  AND JSON_UNQUOTE(JSON_EXTRACT(a.context, '$.branch_id')) = :branch
            ) latest WHERE latest.revision = 1
            SQL,
            [
                'tenant' => $tenant->id(), 'action' => self::MINIMUM_ACTION,
                'type' => ProductVariant::class, 'source' => $source->id(),
                'branch' => $branch->id(),
            ],
        )->fetchAllAssociative();
        $visible = [];
        foreach ($rows as $row) {
            $context = self::auditContext($row['context'] ?? null);
            $variantId = $row['entityId'] ?? null;
            if (!is_string($variantId)
                || ($context['source_id'] ?? null) !== $source->id()
                || ($context['branch_id'] ?? null) !== $branch->id()) {
                continue;
            }
            if (!array_key_exists($variantId, $visible)) {
                $minimum = $context['minimum'] ?? null;
                if (!is_int($minimum) || $minimum < 0 || $minimum > 2147483647) {
                    throw new ServiceUnavailableHttpException(null, 'Mínimo almacenado inválido.');
                }
                $visible[$variantId] = [
                    'variant_id' => $variantId, 'source_id' => $source->id(),
                    'minimum' => $minimum,
                ];
            }
        }
        ksort($visible, SORT_STRING);

        return $this->json(['source_id' => $source->id(), 'minimums' => array_values($visible)]);
    }

    #[Route('/api/v1/branches/{branchId}/inventory/stock-alerts/minimums/{variantId}', name: 'api_stock_minimum_set', methods: ['PUT'])]
    public function setMinimum(string $branchId, string $variantId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'inventory.update');
        $source = $this->branchSource($tenant, $branch);
        $data = $this->payload($request, ['minimum']);
        if (!array_key_exists('minimum', $data)
            || !is_int($data['minimum']) || $data['minimum'] < 0
            || $data['minimum'] > 2147483647) {
            throw new UnprocessableEntityHttpException('El mínimo debe ser un entero no negativo.');
        }
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId, 'tenant' => $tenant, 'active' => true,
        ]);
        if (!$variant instanceof ProductVariant || !$variant->product()->isActive()) {
            throw new NotFoundHttpException('Variante no encontrada en este tenant.');
        }

        // Serialize configuration changes per source. AuditEvent is append-only
        // and supplies the persisted threshold until a dedicated setting entity
        // can be introduced under a separately reserved migration.
        $minimum = $data['minimum'];
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use (
            $source, $tenant, $branch, $variant, $user, $minimum,
        ): JsonResponse {
            $em->refresh($source, LockMode::PESSIMISTIC_WRITE);
            if (!$source->isActive()) {
                throw new NotFoundHttpException('Fuente de inventario activa no encontrada.');
            }
            // The locked source serializes PUTs; query only the matching
            // source/branch/variant's most recent durable threshold.
            $latest = $em->getConnection()->executeQuery(
                <<<'SQL'
                SELECT a.context FROM condor_audit_event a
                WHERE a.tenant_id = :tenant AND a.action = :action
                  AND a.entity_type = :type AND a.entity_id = :variant
                  AND JSON_UNQUOTE(JSON_EXTRACT(a.context, '$.source_id')) = :source
                  AND JSON_UNQUOTE(JSON_EXTRACT(a.context, '$.branch_id')) = :branch
                ORDER BY a.created_at DESC, a.id DESC LIMIT 1
                SQL,
                [
                    'tenant' => $tenant->id(), 'action' => self::MINIMUM_ACTION,
                    'type' => ProductVariant::class, 'variant' => $variant->id(),
                    'source' => $source->id(), 'branch' => $branch->id(),
                ],
            )->fetchAssociative();
            $previous = $latest === false ? null
                : (self::auditContext($latest['context'] ?? null)['minimum'] ?? null);
            if ($latest !== false && (!is_int($previous) || $previous < 0 || $previous > 2147483647)) {
                throw new ServiceUnavailableHttpException(null, 'Mínimo almacenado inválido.');
            }
            if ($previous !== $minimum) {
                $em->persist(new AuditEvent(
                    $tenant, $user->id(), self::MINIMUM_ACTION,
                    ProductVariant::class, $variant->id(),
                    ['source_id' => $source->id(), 'branch_id' => $branch->id(), 'minimum' => $minimum],
                ));
            }

            return $this->json([
                'minimum' => [
                    'variant_id' => $variant->id(),
                    'source_id' => $source->id(), 'minimum' => $minimum,
                ],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private static function auditContext(mixed $raw): array
    {
        try {
            $context = is_string($raw)
                ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
        } catch (\JsonException) {
            throw new ServiceUnavailableHttpException(null, 'Contexto de mínimo inválido.');
        }
        if (!is_array($context)) {
            throw new ServiceUnavailableHttpException(null, 'Contexto de mínimo inválido.');
        }
        return $context;
    }

    private function branchSource(Tenant $tenant, Branch $branch): InventorySource
    {
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'legalEntity' => $branch->legalEntity(),
            'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH,
            'active' => true,
        ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException('Fuente de inventario activa no encontrada.');
        }
        return $source;
    }
}
