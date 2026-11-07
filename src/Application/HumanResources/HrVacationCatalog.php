<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** A fictional calendar, not employee leave or accrued employment benefits. */
final class HrVacationCatalog
{
    /** @var array<string, array{label: string, from: string, until: string}> */
    private const CALENDAR = [
        'cal-01' => ['label' => 'Jornada ficticia de descanso A', 'from' => '2027-01-12', 'until' => '2027-01-16'],
        'cal-02' => ['label' => 'Jornada ficticia de descanso B', 'from' => '2027-04-05', 'until' => '2027-04-09'],
        'cal-03' => ['label' => 'Jornada ficticia de descanso C', 'from' => '2027-07-19', 'until' => '2027-07-23'],
    ];

    /** @return list<array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $this->requireMembership($tenant, $company, $branch);

        $calendar = [];
        foreach (self::CALENDAR as $slot => $period) {
            $calendar[] = [
                'ref' => $this->scopedToken($tenant, $company, $branch, $slot),
                'label' => $period['label'],
                'starts_on' => $period['from'],
                'ends_on' => $period['until'],
                'state' => 'ficticio',
                'synthetic' => true,
            ];
        }

        return $calendar;
    }

    /** @return array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $candidates = $this->listFor($tenant, $company, $branch);
        if (preg_match('/\Afixture-vacation-[a-f0-9]{24}\z/D', $ref) !== 1) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate['ref'], $ref)) {
                return $candidate;
            }
        }
        return null;
    }

    private function requireMembership(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        $tenantId = $tenant->id();
        $isCompanyOwned = $company->tenant()->id() === $tenantId;
        $isBranchOwned = $branch->tenant()->id() === $tenantId;
        $matchesLegalEntity = $branch->legalEntity()?->id() === $company->id();
        if (!($isCompanyOwned && $isBranchOwned && $matchesLegalEntity)) {
            throw new DomainException('No se puede consultar un calendario ficticio de otro contexto.');
        }
    }

    private function scopedToken(Tenant $tenant, LegalEntity $company, Branch $branch, string $slot): string
    {
        $scope = json_encode([
            'tenant' => $tenant->id(),
            'company' => $company->id(),
            'branch' => $branch->id(),
            'slot' => $slot,
        ], JSON_THROW_ON_ERROR);

        return 'fixture-vacation-'.substr(hash('sha256', $scope), 0, 24);
    }
}
