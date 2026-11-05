<?php

declare(strict_types=1);

namespace App\Application\Observability;

use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Commercial\UsageLedger;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Snapshot operativo de UN tenant para el propietario de plataforma. Solo lee
 * datos filtrados por tenant; lo que no tiene fuente por tenant (errores de
 * jobs, incidentes) se declara `no_observado` en vez de mezclar tenants.
 */
final readonly class TenantOperationalSnapshot
{
    public const MAX_METRICS = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubscriptionEntitlementContextFactory $contexts,
        private EntitlementResolver $resolver,
    ) {
    }

    /** @return array<string,mixed> */
    public function forTenant(string $tenantId, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $base = [
            'schema' => 1,
            'tenant_id' => $tenantId,
            'generated_at' => $at->format('Y-m-d\TH:i:s\Z'),
            'job_errors' => self::unobserved('sin fuente de errores de jobs por tenant'),
            'diagnostics' => self::unobserved('los incidentes no están asociados a un tenant'),
        ];

        try {
            $subscription = $this->entityManager->getRepository(Subscription::class)
                ->findOneBy(['tenantId' => $tenantId]);
            if (!$subscription instanceof Subscription) {
                return $base + ['plan' => ['status' => 'not_configured'], 'limits_usage' => [], 'ledger' => self::unobserved('sin suscripción')];
            }
            if (in_array($subscription->state(), [SubscriptionState::Suspended, SubscriptionState::Cancelled], true)) {
                return $base + ['plan' => ['status' => $subscription->state()->value], 'limits_usage' => [], 'ledger' => self::unobserved('contrato no vigente')];
            }
            $effective = $this->resolver->resolve($this->contexts->forTenant($tenantId, $at));
            if (!hash_equals($tenantId, $effective->tenantId())) {
                throw new \DomainException('tenant_mismatch');
            }
            $version = $subscription->planVersion();
            $limits = $effective->limits();
            $ledger = new UsageLedger($tenantId);
            foreach ($this->entityManager->getRepository(UsageObservation::class)->findBy(['tenantId' => $tenantId]) as $row) {
                $ledger->add($row->toRecord());
            }
            $usage = [];
            foreach ($ledger->snapshot() as $row) {
                $start = new DateTimeImmutable($row['window_start']);
                $end = new DateTimeImmutable($row['window_end']);
                $limit = $limits[$row['metric']] ?? null;
                if ($start > $at || $end <= $at || !is_int($limit)) {
                    continue;
                }
                $usage[] = ['metric' => $row['metric'], 'used' => $row['quantity'], 'limit' => $limit];
            }
            usort($usage, static fn (array $a, array $b): int => $a['metric'] <=> $b['metric']);

            return self::scrub($base + [
                'plan' => [
                    'status' => $subscription->state()->value,
                    'key' => $version->plan()->key(),
                    'version' => $version->version(),
                ],
                'limits_usage' => array_slice($usage, 0, self::MAX_METRICS),
                'ledger' => ['status' => 'observado', 'buckets' => count($ledger->snapshot()), 'active_metrics' => count($usage)],
            ]);
        } catch (Throwable) {
            return $base + ['plan' => ['status' => 'no_observado'], 'limits_usage' => [], 'ledger' => self::unobserved('fallo al leer el ledger')];
        }
    }

    /** @return array{status:string,reason:string} */
    private static function unobserved(string $reason): array
    {
        return ['status' => 'no_observado', 'reason' => $reason];
    }

    /**
     * Redacta por defecto cualquier cadena con forma de secreto o correo.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function scrub(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = preg_replace(
                    ['/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '/\bBearer\s+\S+/i', '/\b(password|token|secret|api[_-]?key)\b\s*[:=]\s*\S+/i'],
                    '[REDACTED]',
                    $value,
                ) ?? '[REDACTED]';
            }
        });

        return $data;
    }
}
