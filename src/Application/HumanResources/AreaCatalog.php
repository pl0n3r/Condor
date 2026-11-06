<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Read-only fixture catalog: no personnel data, database port or legal effect. */
final class AreaCatalog
{
    private const AREAS = [
        ['slug' => 'operaciones', 'label' => 'Área de operaciones', 'area' => 'operaciones'],
        ['slug' => 'soporte', 'label' => 'Área de soporte', 'area' => 'soporte'],
        ['slug' => 'calidad', 'label' => 'Área de calidad', 'area' => 'calidad'],
    ];

    /** @return list<array{ref: string, label: string, area: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = self::scopeRef($tenant, $company, $branch);
        $result = [];
        foreach (self::AREAS as $area) {
            $result[] = [
                'ref' => 'fixture-area-'.$scope.'-'.$area['slug'],
                'label' => $area['label'],
                'area' => $area['area'],
                'synthetic' => true,
            ];
        }

        return $result;
    }

    /** @return array{ref: string, label: string, area: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Validate the entire scope before looking up even a synthetic identifier.
        $areas = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-area-[a-f0-9]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach ($areas as $area) {
            if ($area['ref'] === $ref) {
                return $area;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de áreas ficticios no disponible.');
        }
    }

    private static function scopeRef(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        return substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);
    }
}
