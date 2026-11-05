<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use DomainException;

/**
 * Proyecciones M01 exclusivamente sintéticas: sin repositorio, PII,
 * efectos ni aprobaciones. El HTTP caller autoriza sede/empresa/OWNER.
 */
final class HumanResourcesPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function project(
        string $kind,
        string $tenantId,
        string $companyId,
        array $payload,
    ): array {
        $keys = match ($kind) {
            'structure' => ['as_of', 'areas', 'sites', 'positions'],
            'leave' => ['as_of', 'balances', 'requests'],
            'performance' => ['as_of', 'evaluations', 'viewer_employee_ref'],
            default => throw new DomainException('Tipo M01 no disponible.'),
        };
        $given = array_keys($payload);
        sort($given);
        sort($keys);
        if ($given !== $keys || $tenantId === '' || $companyId === '') {
            throw new DomainException('Proyección M01 con contexto o campos inválidos.');
        }

        // El contexto es exclusivamente derivado en servidor; ningún cliente
        // puede inyectar tenant_ref o company_ref de otra compañía.
        $scoped = [
            'tenant_ref' => 'tenant-test-hr'.substr(
                hash('sha256', $tenantId.'|'.$companyId), 0, 24,
            ),
            'company_ref' => 'fixture:company/c'.substr(
                hash('sha256', $companyId.'|'.$tenantId), 0, 24,
            ),
        ] + $payload;

        $result = match ($kind) {
            'structure' => OrganizationStructureProjection::project($scoped),
            'leave' => LeaveAbsenceProjection::project($scoped),
            'performance' => PerformancePortalProjection::project($scoped),
        };
        if (($result['valid'] ?? false) !== true) {
            throw new DomainException('La proyección M01 solo admite datos ficticios válidos.');
        }

        return $result;
    }
}
