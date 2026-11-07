<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** M01 S11: portal ficticio determinista. Nunca accede a empleados ni persiste datos. */
final class HrPortalCatalog
{
    private const ITEMS = [
        ['slug' => 'solicitudes', 'section' => 'SOLICITUDES', 'title' => 'Solicitudes de ejemplo',
            'description' => 'Consulta de solicitudes simuladas, sin efectos laborales.'],
        ['slug' => 'documentos', 'section' => 'DOCUMENTOS', 'title' => 'Documentos de ejemplo',
            'description' => 'Bandeja ficticia; no contiene documentos personales.'],
        ['slug' => 'avisos', 'section' => 'AVISOS', 'title' => 'Avisos de ejemplo',
            'description' => 'Mensajes de demostración sin destinatarios reales.'],
    ];

    /** @return list<array{ref:string,section:string,title:string,description:string,state:string,synthetic:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);

        return array_map(static fn (array $item): array => [
            'ref' => 'fixture-hr-portal-'.$scope.'-'.$item['slug'],
            'section' => $item['section'],
            'title' => $item['title'],
            'description' => $item['description'],
            'state' => 'SOLO_LECTURA',
            'synthetic' => true,
        ], self::ITEMS);
    }

    /** @return array{ref:string,section:string,title:string,description:string,state:string,synthetic:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        if (strlen($ref) > 90 || preg_match('/\Afixture-hr-portal-[a-f0-9]{16}-[a-z]+\z/D', $ref) !== 1) {
            return null;
        }
        foreach ($this->listFor($tenant, $company, $branch) as $item) {
            if (hash_equals($item['ref'], $ref)) {
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
            throw new DomainException('Contexto del portal ficticio no disponible.');
        }
    }
}
