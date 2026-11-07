<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Isolated, read-only fictitious reporting hierarchy. Never queries employees. */
final class HrOrgChartCatalog
{
    private const NODES = [
        ['slug' => 'direccion', 'label' => 'Dirección ficticia', 'parent' => null],
        ['slug' => 'operaciones', 'label' => 'Coordinación de operaciones ficticia', 'parent' => 'direccion'],
        ['slug' => 'calidad', 'label' => 'Equipo de calidad ficticio', 'parent' => 'operaciones'],
        ['slug' => 'soporte', 'label' => 'Equipo de soporte ficticio', 'parent' => 'direccion'],
    ];

    /** @return list<array{ref: string, parent_ref: ?string, label: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);
        $result = [];
        foreach (self::NODES as $node) {
            $result[] = [
                'ref' => 'fixture-org-'.$scope.'-'.$node['slug'],
                'parent_ref' => $node['parent'] === null ? null : 'fixture-org-'.$scope.'-'.$node['parent'],
                'label' => $node['label'],
                'synthetic' => true,
            ];
        }

        return $result;
    }

    /** @return array{ref: string, parent_ref: ?string, label: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $nodes = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-org-[a-f0-9]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach ($nodes as $node) {
            if (hash_equals($node['ref'], $ref)) {
                return $node;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de organigrama ficticio no disponible.');
        }
    }
}
