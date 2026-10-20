<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Production\Entity\ProductionOrder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A branch-scoped, strictly read-only management dashboard.
 *
 * Sales are settled orders, not inferred profit. Finished production costs
 * remain estimated even when their original immutable snapshot was saved.
 * Stock has no authoritative per-variant cost in InventoryBalance, therefore
 * its valuation is UNKNOWN rather than a fabricated zero or selling price.
 */
final class ManagementDashboardController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/management/dashboard', name: 'api_management_dashboard', methods: ['GET'])]
    public function dashboard(string $branchId, Request $request): JsonResponse
    {
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.view');
        $this->authorization->require($user, $tenant, $branch, 'inventory.view');

        $to = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $defaultFrom = $to->modify('-29 days');
        $from = self::date($request->query->get('from', $defaultFrom->format('Y-m-d')));
        $through = self::date($request->query->get('through', $to->format('Y-m-d')));
        if ($from > $through || $from->diff($through)->days > 366) {
            throw new BadRequestHttpException('El período debe estar ordenado y limitado a 367 días.');
        }

        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'branch' => $branch,
        ]);

        $sales = [];
        $byCurrency = [];
        $inventory = [];
        $production = [];
        if ($source instanceof InventorySource) {
            foreach ($this->entityManager->getRepository(Order::class)->findBy([
                'tenant' => $tenant,
                'inventorySource' => $source,
            ]) as $order) {
                if (!$order instanceof Order
                    || $order->createdAt() < $from
                    || $order->createdAt() >= $through->modify('+1 day')
                    || $order->paymentStatus() !== Order::PAYMENT_PAID
                    || $order->orderStatus() === Order::STATUS_CANCELLED
                    || $order->fulfillmentStatus() !== Order::FULFILLMENT_CONSUMED
                ) {
                    continue;
                }

                $currency = $order->currency();
                $amount = $order->totalAmountMinor();
                if ($amount < 0 || $amount > PHP_INT_MAX - ($byCurrency[$currency] ?? 0)) {
                    throw new BadRequestHttpException('El total de ventas excede el rango verificable.');
                }
                $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
                $sales[] = [
                    'id' => $order->id(),
                    'currency' => $currency,
                    'amount_minor' => $amount,
                    'occurred_at' => $order->createdAt()->format(DATE_ATOM),
                    'source_url' => $this->sourceUrl($branchId, 'sale', $order->id()),
                ];
            }

            foreach ($this->entityManager->getRepository(InventoryBalance::class)->findBy([
                'tenant' => $tenant,
                'source' => $source,
            ]) as $balance) {
                if (!$balance instanceof InventoryBalance) {
                    continue;
                }
                $inventory[] = [
                    'id' => $balance->id(),
                    'variant_id' => $balance->variant()->id(),
                    'quantity' => $balance->quantity(),
                    'reserved_quantity' => $balance->reservedQuantity(),
                    'valuation_minor' => null,
                    'valuation_state' => 'UNKNOWN',
                    'source_url' => $this->sourceUrl($branchId, 'inventory', $balance->id()),
                ];
            }

            foreach ($this->entityManager->getRepository(ProductionOrder::class)->findBy([
                'tenant' => $tenant,
                'source' => $source,
            ]) as $order) {
                if (!$order instanceof ProductionOrder
                    || !$order->isCompleted()
                    || $order->completedAt() === null
                    || $order->completedAt() < $from
                    || $order->completedAt() >= $through->modify('+1 day')
                ) {
                    continue;
                }
                $saved = $order->costSnapshot();
                $valid = self::isSavedCost($saved, $tenant->id(), $order->id());
                $production[] = [
                    'id' => $order->id(),
                    'cost_state' => $valid ? 'ESTIMATED_COST' : 'UNKNOWN',
                    'estimated_total_cost' => $valid ? $saved['total_cost'] : null,
                    'currency' => null,
                    'source_url' => $this->sourceUrl($branchId, 'production', $order->id()),
                ];
            }
        }

        ksort($byCurrency, SORT_STRING);

        return $this->json([
            'period' => ['from' => $from->format('Y-m-d'), 'through' => $through->format('Y-m-d')],
            'branch_id' => $branch->id(),
            'sales' => [
                'state' => $sales === [] ? 'EMPTY' : 'PAID_AND_FULFILLED',
                'by_currency_minor' => $byCurrency,
                'records' => $sales,
                'notice' => 'Ventas cobradas y entregadas; no equivale a utilidad realizada.',
            ],
            'inventory' => [
                'state' => 'UNKNOWN',
                'valuation_minor' => null,
                'reason' => 'Sin costo unitario acreditado para valorizar saldos de inventario.',
                'records' => $inventory,
            ],
            'production' => [
                'state' => $production === [] ? 'EMPTY' : 'ESTIMATED_ONLY',
                'records' => $production,
                'notice' => 'Costo estimado de producción, nunca utilidad realizada.',
            ],
        ]);
    }

    /**
     * Detail URLs only resolve records after checking branch, current tenant
     * and both reporting permissions again. They never expose another tenant.
     */
    #[Route('/api/v1/branches/{branchId}/management/sources/{kind}/{recordId}', name: 'api_management_source', methods: ['GET'])]
    public function record(string $branchId, string $kind, string $recordId): JsonResponse
    {
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'orders.view');
        $this->authorization->require($user, $tenant, $branch, 'inventory.view');
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'branch' => $branch,
        ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException('Registro fuente no encontrado.');
        }

        if ($kind === 'sale') {
            $order = $this->entityManager->getRepository(Order::class)->findOneBy([
                'id' => $recordId, 'tenant' => $tenant, 'inventorySource' => $source,
            ]);
            if (!$order instanceof Order
                || $order->paymentStatus() !== Order::PAYMENT_PAID
                || $order->fulfillmentStatus() !== Order::FULFILLMENT_CONSUMED
                || $order->orderStatus() === Order::STATUS_CANCELLED
            ) {
                throw new NotFoundHttpException('Venta no encontrada.');
            }

            return $this->json([
                'kind' => 'sale', 'id' => $order->id(),
                'currency' => $order->currency(),
                'amount_minor' => $order->totalAmountMinor(),
                'payment_status' => $order->paymentStatus(),
                'fulfillment_status' => $order->fulfillmentStatus(),
            ]);
        }

        if ($kind === 'inventory') {
            $balance = $this->entityManager->getRepository(InventoryBalance::class)->findOneBy([
                'id' => $recordId, 'tenant' => $tenant, 'source' => $source,
            ]);
            if (!$balance instanceof InventoryBalance) {
                throw new NotFoundHttpException('Saldo no encontrado.');
            }

            return $this->json([
                'kind' => 'inventory', 'id' => $balance->id(),
                'variant_id' => $balance->variant()->id(),
                'quantity' => $balance->quantity(),
                'reserved_quantity' => $balance->reservedQuantity(),
                'valuation_state' => 'UNKNOWN',
                'valuation_minor' => null,
            ]);
        }

        if ($kind === 'production') {
            $order = $this->entityManager->getRepository(ProductionOrder::class)->findOneBy([
                'id' => $recordId, 'tenant' => $tenant, 'source' => $source,
            ]);
            if (!$order instanceof ProductionOrder || !$order->isCompleted()) {
                throw new NotFoundHttpException('Orden terminada no encontrada.');
            }
            $saved = $order->costSnapshot();
            $valid = self::isSavedCost($saved, $tenant->id(), $order->id());

            return $this->json([
                'kind' => 'production', 'id' => $order->id(),
                'cost_state' => $valid ? 'ESTIMATED_COST' : 'UNKNOWN',
                'estimated_total_cost' => $valid ? $saved['total_cost'] : null,
                'currency' => null,
            ]);
        }

        throw new NotFoundHttpException('Tipo de registro fuente desconocido.');
    }

    /**
     * A directly accessible read-only screen, without touching the existing
     * admin shell or its independently reserved compiled Vite bundle.
     */
    #[Route('/admin/management/{branchId}', name: 'app_management_dashboard', methods: ['GET'])]
    public function page(string $branchId, Request $request): Response
    {
        $payload = json_decode((string) $this->dashboard($branchId, $request)->getContent(), true);
        if (!is_array($payload)) {
            throw new NotFoundHttpException('Resumen no disponible.');
        }

        $safe = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $list = static function (array $rows, string $amountField) use ($safe): string {
            if ($rows === []) {
                return '<p>Sin registros en este período.</p>';
            }
            $html = '<ul>';
            foreach ($rows as $row) {
                $amount = $row[$amountField] ?? null;
                $detail = $amount === null ? 'UNKNOWN' : (string) $amount;
                $html .= '<li><a href="'.$safe($row['source_url']).'">Registro '.$safe($row['id']).'</a>: '.$safe($detail).'</li>';
            }
            return $html.'</ul>';
        };
        $sales = $list($payload['sales']['records'], 'amount_minor');
        $stock = $list($payload['inventory']['records'], 'valuation_minor');
        $costs = $list($payload['production']['records'], 'estimated_total_cost');

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Panel gerencial · Condor</title></head><body>'
            .'<main><h1>Panel gerencial (solo lectura)</h1><p>Período: '
            .$safe($payload['period']['from']).' a '.$safe($payload['period']['through']).'</p>'
            .'<section><h2>Ventas cobradas y entregadas</h2>'
            .$sales.'<p>No representan utilidad realizada.</p></section>'
            .'<section><h2>Inventario valorizado: UNKNOWN</h2><p>'
            .$safe($payload['inventory']['reason']).'</p>'.$stock.'</section>'
            .'<section><h2>Costo de producción (estimado)</h2>'
            .$costs.'<p>No representa utilidad realizada.</p></section></main></body></html>';

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function sourceUrl(string $branchId, string $kind, string $id): string
    {
        return $this->generateUrl('api_management_source', [
            'branchId' => $branchId,
            'kind' => $kind,
            'recordId' => $id,
        ]);
    }

    /** @param mixed $value */
    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new BadRequestHttpException('Fecha inválida.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Fecha inválida.');
        }

        return $date;
    }

    /** @param mixed $snapshot */
    private static function isSavedCost(mixed $snapshot, string $tenantId, string $orderId): bool
    {
        return is_array($snapshot)
            && ($snapshot['tenant_id'] ?? null) === $tenantId
            && ($snapshot['order_id'] ?? null) === $orderId
            && ($snapshot['state'] ?? null) === 'ESTIMATED_COST'
            && is_string($snapshot['total_cost'] ?? null)
            && (bool) preg_match('/^(0|[1-9]\d*)\.\d{4}$/D', $snapshot['total_cost']);
    }
}
