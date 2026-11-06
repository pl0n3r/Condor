<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** M01 S04: immutable fictional branch previews, scoped to an authorized tenant, company and branch. */
final class HrBranchCatalog
{
    private const BRANCHES = [
        ['slug' => 'norte', 'label' => 'Sede ficticia Norte', 'kind' => 'ensayo'],
        ['slug' => 'centro', 'label' => 'Sede ficticia Centro', 'kind' => 'ensayo'],
        ['slug' => 'sur', 'label' => 'Sede ficticia Sur', 'kind' => 'ensayo'],
    ];

    /** @return list<array{ref: string, label: string, kind: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = self::scopeRef($tenant, $company, $branch);
        $result = [];
        foreach (self::BRANCHES as $entry) {
            $result[] = [
                'ref' => 'fixture-branch-'.$scope.'-'.$entry['slug'],
                'label' => $entry['label'],
                'kind' => $entry['kind'],
                'synthetic' => true,
            ];
        }

        return $result;
    }

    /** @return array{ref: string, label: string, kind: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Validate the entire scope before looking up even a synthetic identifier.
        $entries = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-branch-[a-f0-9]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry['ref'] === $ref) {
                return $entry;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de sedes ficticias no disponible.');
        }
    }

    private static function scopeRef(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        return substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);
    }
}
