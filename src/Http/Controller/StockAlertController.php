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
    private const MAX_REVISIONS = 500;

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
        $candidateDeliveries = $this->entityManager->getRepository(NotificationDelivery::class)->findBy(
            [
                'user' => $user,
                'eventKey' => NotificationCatalog::EVENT_STOCK_LOW,
                'channelKey' => NotificationCatalog::CHANNEL_IN_APP,
                // Apply active-state filtering before the 200-row limit:
                // a backlog of failed deliveries must not hide valid alerts.
                'status' => [
                    NotificationDelivery::STATUS_PENDING,
                    NotificationDelivery::STATUS_DELIVERED,
                ],
            ],
            ['createdAt' => 'DESC'],
            200,
        );
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
        // Never silently drop older settings if the bounded log is saturated.
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.entityId', 'a.context')
            ->from(AuditEvent::class, 'a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.action = :action')
            ->andWhere('a.entityType = :type')
            ->setParameter('tenant', $tenant)
            ->setParameter('action', self::MINIMUM_ACTION)
            ->setParameter('type', ProductVariant::class)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::MAX_REVISIONS + 1)
            ->getQuery()->getArrayResult();
        if (count($rows) > self::MAX_REVISIONS) {
            throw new ServiceUnavailableHttpException(null, 'Registro de mínimos excede el límite verificable.');
        }
        $visible = [];
        foreach ($rows as $row) {
            $context = $row['context'] ?? null;
            $variantId = $row['entityId'] ?? null;
            if (!is_string($variantId) || !is_array($context)
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
            $latest = $em->createQueryBuilder()
                ->select('a.context')
                ->from(AuditEvent::class, 'a')
                ->where('a.tenant = :tenant')
                ->andWhere('a.action = :action')
                ->andWhere('a.entityType = :type')
                ->andWhere('a.entityId = :id')
                ->setParameter('tenant', $tenant)
                ->setParameter('action', self::MINIMUM_ACTION)
                ->setParameter('type', ProductVariant::class)
                ->setParameter('id', $variant->id())
                ->orderBy('a.createdAt', 'DESC')
                ->addOrderBy('a.id', 'DESC')
                ->setMaxResults(self::MAX_REVISIONS + 1)
                ->getQuery()->getArrayResult();
            // Other branches share a variant's audit history. Never treat a
            // truncated search as "no prior setting": that would break the
            // idempotent PUT contract for this branch.
            if (count($latest) > self::MAX_REVISIONS) {
                throw new ServiceUnavailableHttpException(
                    null,
                    'Historial de mínimos excede el límite verificable.',
                );
            }
            $previous = null;
            foreach ($latest as $row) {
                $c = $row['context'] ?? null;
                if (is_array($c) && ($c['source_id'] ?? null) === $source->id()
                    && ($c['branch_id'] ?? null) === $branch->id()) {
                    $previous = $c['minimum'] ?? null;
                    break;
                }
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
