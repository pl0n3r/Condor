<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Read-only fixture catalog: no personnel data, database port or legal effect. */
final class PositionCatalog
{
    private const POSITIONS = [
        ['slug' => 'operaciones', 'label' => 'Coordinación operativa', 'area' => 'operaciones'],
        ['slug' => 'soporte', 'label' => 'Soporte interno', 'area' => 'soporte'],
        ['slug' => 'calidad', 'label' => 'Verificación de calidad', 'area' => 'calidad'],
    ];

    /** @return list<array{ref: string, label: string, area: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = self::scopeRef($tenant, $company, $branch);
        $result = [];
        foreach (self::POSITIONS as $position) {
            $result[] = [
                'ref' => 'fixture-position-'.$scope.'-'.$position['slug'],
                'label' => $position['label'],
                'area' => $position['area'],
                'synthetic' => true,
            ];
        }

        return $result;
    }

    /** @return array{ref: string, label: string, area: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Validate the entire scope before looking up even a synthetic identifier.
        $positions = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-position-[a-f0-9]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach ($positions as $position) {
            if ($position['ref'] === $ref) {
                return $position;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de cargos ficticios no disponible.');
        }
    }

    private static function scopeRef(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        return substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);
    }
}
