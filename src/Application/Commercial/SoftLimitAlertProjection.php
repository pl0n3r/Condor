<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\UsageMetric;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

/**
 * Avisos informativos sobre Usage y Entitlements ya resueltos por la autoridad
 * comercial existente. No escribe, cambia permisos, cobra ni bloquea acciones.
 */
final class SoftLimitAlertProjection
{
    /**
     * @return array{status:'valid'|'unavailable',reason:string|null,classification:string|null,tenant_id:string|null,source:string|null,window_start:string|null,window_end:string|null,usage:list<array{metric:string,used:int,limit:int}>|null,alerts:list<array{metric:string,threshold_percent:80|100,message:string}>|null,enforcement:bool}
     */
    public static function derive(
        UsageLedger $usage,
        EntitlementSnapshot $entitlements,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        bool $canView,
        string $classification,
    ): array {
        try {
            if ($classification !== 'synthetic') {
                throw new DomainException('synthetic_only');
            }
            if (!$canView) {
                throw new DomainException('permission_denied');
            }
            $tenantId = $entitlements->tenantId();
            if (
                preg_match('/^tenant-[a-z0-9-]{1,32}$/D', $tenantId) !== 1
                || !hash_equals($tenantId, $usage->tenantId())
            ) {
                throw new DomainException('tenant_mismatch');
            }
            if (
                $windowStart >= $windowEnd
                || $entitlements->evaluatedAt() < $windowStart
                || $entitlements->evaluatedAt() >= $windowEnd
            ) {
                throw new DomainException('invalid_window');
            }

            $limits = $entitlements->limits();
            if ($limits === []) {
                throw new DomainException('missing_entitlements');
            }
            $measurements = [];
            $alerts = [];
            foreach ($limits as $key => $value) {
                if (!is_int($value) || $value < 1) {
                    throw new DomainException('invalid_entitlement_limit');
                }
                // Los controles de seguridad y privacidad nunca son medidores comerciales.
                if (in_array($key, ['security', 'privacy', 'backup', 'recovery', 'integrity'], true)) {
                    throw new DomainException('protected_control');
                }
                $metric = UsageMetric::tryFrom($key);
                if (!$metric instanceof UsageMetric) {
                    throw new DomainException('unsupported_usage_metric');
                }
                $quantity = $usage->quantity($metric, $windowStart, $windowEnd);
                if ($quantity === null || $quantity < 0) {
                    throw new DomainException('missing_usage_evidence');
                }
                $measurements[] = ['metric' => $key, 'used' => $quantity, 'limit' => $value];
                // ceil(0.8 * limit) sin float ni multiplicar enteros enormes por 80.
                $warning = intdiv($value, 5) * 4 + intdiv(($value % 5) * 4 + 4, 5);
                $threshold = $quantity >= $value ? 100 : ($quantity >= $warning ? 80 : null);
                if ($threshold !== null) {
                    $alerts[] = [
                        'metric' => $key,
                        'threshold_percent' => $threshold,
                        'message' => $threshold === 100
                            ? 'Aviso: se alcanzó el límite de uso.'
                            : 'Aviso: se alcanzó al menos el 80 % del límite de uso.',
                    ];
                }
            }
            usort($measurements, static fn (array $a, array $b): int => $a['metric'] <=> $b['metric']);
            usort($alerts, static fn (array $a, array $b): int => $a['metric'] <=> $b['metric']);

            return [
                'status' => 'valid', 'reason' => null, 'classification' => 'synthetic',
                'tenant_id' => $tenantId, 'source' => 'usage-ledger+entitlement-snapshot',
                'window_start' => self::utc($windowStart), 'window_end' => self::utc($windowEnd),
                'usage' => $measurements, 'alerts' => $alerts, 'enforcement' => false,
            ];
        } catch (Throwable $error) {
            return [
                'status' => 'unavailable',
                'reason' => $error instanceof DomainException ? $error->getMessage() : 'invalid_source_evidence',
                'classification' => null, 'tenant_id' => null, 'source' => null,
                'window_start' => null, 'window_end' => null,
                'usage' => null, 'alerts' => null, 'enforcement' => false,
            ];
        }
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
