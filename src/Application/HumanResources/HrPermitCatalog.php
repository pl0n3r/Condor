<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Deterministic read-only permission requests; never employees, balances or HR records. */
final class HrPermitCatalog
{
    private const PERMITS = [
        ['slug' => 'personal', 'label' => 'Permiso personal ficticio', 'starts_on' => '2030-01-10', 'ends_on' => '2030-01-10'],
        ['slug' => 'tramite', 'label' => 'Trámite ficticio', 'starts_on' => '2030-02-12', 'ends_on' => '2030-02-12'],
        ['slug' => 'familiar', 'label' => 'Permiso familiar ficticio', 'starts_on' => '2030-03-15', 'ends_on' => '2030-03-15'],
    ];

    /** @return list<array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool, automatically_approved: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);

        $result = [];
        foreach (self::PERMITS as $permit) {
            $result[] = [
                'ref' => 'fixture-permit-'.$scope.'-'.$permit['slug'],
                'label' => $permit['label'],
                'starts_on' => $permit['starts_on'],
                'ends_on' => $permit['ends_on'],
                'state' => 'PENDIENTE',
                'synthetic' => true,
                'automatically_approved' => false,
            ];
        }

        return $result;
    }

    /** @return array{ref: string, label: string, starts_on: string, ends_on: string, state: string, synthetic: bool, automatically_approved: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Scope is checked even when a reference is malformed or belongs elsewhere.
        $permits = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-permit-[a-f0-9]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach ($permits as $permit) {
            if ($permit['ref'] === $ref) {
                return $permit;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de permisos ficticios no disponible.');
        }
    }
}
