<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\SaasRevenueMovements;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use App\Domain\Commercial\SubscriptionChange;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/** Owner-only MRR movement projection. Never creates an invoice or payment. */
final class PlatformSaasRevenueController extends PlatformOwnerApiController
{
    private const MAX_SUBSCRIPTIONS = 2000;
    private const MAX_CHANGES = 10000;

    #[Route('/adminpl0n3r/api/ingresos', name: 'platform_saas_revenue', methods: ['GET'])]
    public function __invoke(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->platformOwner();
        [$from, $to, $planFilter] = self::filters($request);
        try {
            $subscriptions = $entityManager->getRepository(Subscription::class)->findAll();
            $changeRecords = $entityManager->getRepository(SubscriptionChangeRecord::class)->findAll();
            if (count($subscriptions) > self::MAX_SUBSCRIPTIONS || count($changeRecords) > self::MAX_CHANGES) {
                return self::unavailable($this, 'evidence_limit');
            }
            $tenants = [];
            $tenantRows = $entityManager->getRepository(Tenant::class)->findAll();
            if (count($tenantRows) > self::MAX_SUBSCRIPTIONS * 2) {
                return self::unavailable($this, 'evidence_limit');
            }
            foreach ($tenantRows as $tenant) {
                $tenants[$tenant->id()] = $tenant->name();
            }
            $byTenant = [];
            foreach ($subscriptions as $subscription) {
                $tenantId = $subscription->tenantId();
                if (isset($byTenant[$tenantId]) || !isset($tenants[$tenantId])) {
                    return self::unavailable($this, 'incomplete_tenant_scope');
                }
                $byTenant[$tenantId] = $subscription;
            }
            $changes = [];
            $effective = [];
            $scheduled = [];
            foreach ($changeRecords as $record) {
                $change = $record->toChange();
                if (!isset($byTenant[$change->tenantId()])) {
                    return self::unavailable($this, 'incomplete_change_scope');
                }
                $changes[] = $change;
                if ($change->status() === 'effective') {
                    $effective[$change->tenantId()][] = $change;
                } elseif ($change->status() === 'scheduled') {
                    $scheduled[$change->tenantId()][] = $change;
                }
            }
            foreach ($effective as &$history) {
                usort($history, static fn (SubscriptionChange $a, SubscriptionChange $b): int =>
                    $b->effectiveAt() <=> $a->effectiveAt());
            }
            unset($history);
            $projection = SaasRevenueMovements::derive($subscriptions, $changes);
            if ($projection['status'] !== 'valid' || !is_array($projection['movements'])) {
                return self::unavailable($this, 'sin_tarifa_o_evidencia_incompleta');
            }
            $rows = [];
            $plans = [];
            $companies = [];
            $totals = self::totals();
            foreach ($projection['movements'] as $movement) {
                $day = substr($movement['occurred_at'], 0, 10);
                if ($day < $from || $day > $to) {
                    continue;
                }
                $id = $movement['tenant_id'];
                $version = self::planAt($byTenant[$id], $effective[$id] ?? [], $movement['occurred_at']);
                if ($movement['recognition'] === 'scheduled') {
                    foreach ($scheduled[$id] ?? [] as $change) {
                        $at = $change->effectiveAt();
                        if ($at instanceof DateTimeImmutable
                            && $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z') === $movement['occurred_at']) {
                            $version = $change->targetPlan();
                            break;
                        }
                    }
                }
                $key = $version->plan()->key();
                if ($planFilter !== '' && $planFilter !== $key) {
                    continue;
                }
                $entry = [
                    'tenant_id' => $id,
                    'company' => $tenants[$id],
                    'plan_key' => $key,
                    'plan_name' => $version->plan()->name(),
                    'type' => $movement['type'],
                    'recognition' => $movement['recognition'],
                    'occurred_at' => $movement['occurred_at'],
                    'amount' => $movement['amount'],
                    'mrr_delta' => $movement['mrr_delta'],
                ];
                $rows[] = $entry;
                // Scheduled changes are projections, never recognized revenue.
                if ($entry['recognition'] !== 'effective') {
                    continue;
                }
                $plans[$key] ??= ['key' => $key, 'name' => $entry['plan_name']] + self::totals();
                $companies[$id] ??= ['tenant_id' => $id, 'company' => $entry['company']] + self::totals();
                foreach ([$entry['type'] => $entry['amount'], 'mrr_delta' => $entry['mrr_delta']] as $field => $delta) {
                    $plans[$key][$field] = self::safeSum($plans[$key][$field], $delta);
                    $companies[$id][$field] = self::safeSum($companies[$id][$field], $delta);
                    $totals[$field] = self::safeSum($totals[$field], $delta);
                }
            }
            ksort($plans);
            ksort($companies);
            $response = $this->json([
                'status' => 'ready', 'period' => ['from' => $from, 'to' => $to],
                'currency' => $projection['currency'], 'totals' => $totals,
                'plans' => array_values($plans), 'companies' => array_values($companies),
                'movements' => $rows,
            ]);
        } catch (Throwable) {
            return self::unavailable($this, 'incomplete_commercial_evidence');
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    /** @return array{string,string,string} */
    private static function filters(Request $request): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $request->query->get('from', $now->format('Y-m-01'));
        $to = $request->query->get('to', $now->format('Y-m-t'));
        $plan = $request->query->get('plan', '');
        if (!is_string($from) || !is_string($to) || !is_string($plan)) {
            throw new BadRequestHttpException('Filtros inválidos.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
        if (!$start || !$end || $start->format('Y-m-d') !== $from
            || $end->format('Y-m-d') !== $to || $end < $start
            || $end->getTimestamp() - $start->getTimestamp() > 366 * 86400
            || ($plan !== '' && preg_match('/^[a-z0-9-]{1,80}$/D', $plan) !== 1)) {
            throw new BadRequestHttpException('Periodo o plan inválido.');
        }
        return [$from, $to, $plan];
    }

    /** @param list<SubscriptionChange> $history */
    private static function planAt(Subscription $subscription, array $history, string $occurred): PlanVersion
    {
        $version = $subscription->planVersion();
        foreach ($history as $change) {
            $at = $change->effectiveAt();
            if ($at === null) {
                continue;
            }
            if ($version->id() !== $change->targetPlan()->id()) {
                throw new \DomainException('Cadena de planes inconsistente.');
            }
            if ($at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z') > $occurred) {
                $version = $change->currentPlan();
            } else {
                break;
            }
        }
        return $version;
    }

    /** @return array{new:int,expansion:int,contraction:int,churn:int,mrr_delta:int} */
    private static function totals(): array
    {
        return ['new' => 0, 'expansion' => 0, 'contraction' => 0, 'churn' => 0, 'mrr_delta' => 0];
    }

    private static function safeSum(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new \OverflowException('MRR fuera de rango.');
        }
        return $a + $b;
    }

    private static function unavailable(self $controller, string $reason): JsonResponse
    {
        $response = $controller->json(['status' => 'unavailable', 'reason' => $reason]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
