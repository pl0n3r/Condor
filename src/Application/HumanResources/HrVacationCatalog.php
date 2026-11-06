<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Deterministic M01 S08 calendar fixtures. No employee rows, accrued leave,
 * payroll calculations, mutable balances, persistence or legal effect.
 */
final class HrVacationCatalog
{
    private const VACATIONS = [
        ['key' => 'enero-a', 'label' => 'Descanso ficticio A', 'starts_on' => '2027-01-12', 'ends_on' => '2027-01-16'],
        ['key' => 'abril-b', 'label' => 'Descanso ficticio B', 'starts_on' => '2027-04-05', 'ends_on' => '2027-04-09'],
        ['key' => 'julio-c', 'label' => 'Descanso ficticio C', 'starts_on' => '2027-07-19', 'ends_on' => '2027-07-23'],
    ];

    /** @return list<array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);

        $rows = [];
        foreach (self::VACATIONS as $fixture) {
            $rows[] = [
                'ref' => 'fixture-vacation-'.$scope.'-'.$fixture['key'],
                'label' => $fixture['label'],
                'starts_on' => $fixture['starts_on'],
                'ends_on' => $fixture['ends_on'],
                'state' => 'ficticio',
                'synthetic' => true,
            ];
        }
        return $rows;
    }

    /** @return array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Always validate scope before resolving even opaque fixture identifiers.
        $rows = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 96 || !preg_match('/\Afixture-vacation-[a-f0-9]{16}-[a-z0-9-]+\z/D', $ref)) {
            return null;
        }
        foreach ($rows as $row) {
            if ($row['ref'] === $ref) {
                return $row;
            }
        }
        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de vacaciones ficticias no disponible.');
        }
    }
}
