<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Deterministic M01 S06 fixtures; never reads employee records or writes data. */
final class HrChangeCatalog
{
    private const CHANGES = [
        ['slug' => 'ingreso', 'kind' => 'INGRESO_SIMULADO', 'label' => 'Ingreso ficticio', 'date' => '2026-01-05'],
        ['slug' => 'traslado', 'kind' => 'TRASLADO_SIMULADO', 'label' => 'Traslado ficticio', 'date' => '2026-02-10'],
        ['slug' => 'actualizacion', 'kind' => 'CAMBIO_SIMULADO', 'label' => 'Actualización ficticia', 'date' => '2026-03-12'],
    ];

    /** @return list<array{ref:string,kind:string,label:string,effective_on:string,state:string,synthetic:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);

        $rows = [];
        foreach (self::CHANGES as $entry) {
            $rows[] = [
                'ref' => 'fixture-hr-change-'.$scope.'-'.$entry['slug'],
                'kind' => $entry['kind'],
                'label' => $entry['label'],
                'effective_on' => $entry['date'],
                'state' => 'SOLO_LECTURA',
                'synthetic' => true,
            ];
        }

        return $rows;
    }

    /** @return array{ref:string,kind:string,label:string,effective_on:string,state:string,synthetic:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $items = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 90 || preg_match('/\Afixture-hr-change-[a-f0-9]{16}-[a-z]+\z/D', $ref) !== 1) {
            return null;
        }
        foreach ($items as $item) {
            if ($item['ref'] === $ref) {
                return $item;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de novedades ficticias no disponible.');
        }
    }
}
