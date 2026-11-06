<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Synthetic absence snapshots. No employee entity or medical input is used. */
final class HrIncapacityCatalog
{
    /** @var list<array{code:string,label:string,from:string,to:string}> */
    private const SCENARIOS = [
        ['code' => 'a', 'label' => 'Ausencia ficticia A', 'from' => '2030-01-10', 'to' => '2030-01-12'],
        ['code' => 'b', 'label' => 'Ausencia ficticia B', 'from' => '2030-03-05', 'to' => '2030-03-06'],
        ['code' => 'c', 'label' => 'Ausencia ficticia C', 'from' => '2030-06-18', 'to' => '2030-06-20'],
    ];

    /** @return list<array{ref:string,label:string,starts_on:string,ends_on:string,state:string,synthetic:bool,automatically_approved:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $scope = $this->scopeFingerprint($tenant, $company, $branch);

        return array_map(
            static fn (array $item): array => [
                'ref' => 'fixture-incapacity-'.substr(hash('sha256', $scope.'|'.$item['code']), 0, 24),
                'label' => $item['label'],
                'starts_on' => $item['from'],
                'ends_on' => $item['to'],
                'state' => 'SIMULADA',
                'synthetic' => true,
                'automatically_approved' => false,
            ],
            self::SCENARIOS,
        );
    }

    /** @return array{ref:string,label:string,starts_on:string,ends_on:string,state:string,synthetic:bool,automatically_approved:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $reference): ?array
    {
        if (preg_match('/\Afixture-incapacity-[a-f0-9]{24}\z/D', $reference) !== 1) {
            return null;
        }

        foreach ($this->listFor($tenant, $company, $branch) as $entry) {
            if (hash_equals($reference, $entry['ref'])) {
                return $entry;
            }
        }

        return null;
    }

    private function scopeFingerprint(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        $tenantId = $tenant->id();
        if ($tenantId !== $company->tenant()->id()
            || $tenantId !== $branch->tenant()->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('El contexto ficticio de ausencias no pertenece al ámbito solicitado.');
        }

        return hash('sha256', implode('|', [$tenantId, $company->id(), $branch->id()]));
    }
}
