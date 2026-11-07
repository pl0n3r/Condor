<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Catálogo del portal M01 S11: siempre es ficticio, sin PII ni repositorios de empleados. */
final class HrPortalCatalog
{
    /** @var array<string,array{string,string,string}> */
    private const SECTIONS = [
        'solicitudes' => ['SOLICITUDES', 'Solicitudes de ejemplo', 'Trámites simulados sin efectos laborales.'],
        'documentos' => ['DOCUMENTOS', 'Documentos de ejemplo', 'Bandeja de muestra sin documentos personales.'],
        'avisos' => ['AVISOS', 'Avisos de ejemplo', 'Comunicados ficticios sin destinatarios.'],
    ];

    /** @return list<array{ref:string,section:string,title:string,description:string,state:string,synthetic:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $scopeIds = [$tenant->id(), $company->tenant()->id(), $branch->tenant()->id()];
        if (count(array_unique($scopeIds)) !== 1 || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('El portal ficticio no pertenece a este ámbito.');
        }

        $fingerprint = substr(hash('sha256', $tenant->id().'/'.$company->id().'/'.$branch->id()), 0, 16);
        $items = [];
        foreach (self::SECTIONS as $slug => [$section, $title, $description]) {
            $items[] = [
                'ref' => 'fixture-hr-portal-'.$fingerprint.'-'.$slug,
                'section' => $section,
                'title' => $title,
                'description' => $description,
                'state' => 'SOLO_LECTURA',
                'synthetic' => true,
            ];
        }

        return $items;
    }

    /** @return array{ref:string,section:string,title:string,description:string,state:string,synthetic:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $reference): ?array
    {
        if (!preg_match('/\Afixture-hr-portal-[a-f0-9]{16}-(?:solicitudes|documentos|avisos)\z/D', $reference)) {
            return null;
        }

        foreach ($this->listFor($tenant, $company, $branch) as $entry) {
            if (hash_equals($entry['ref'], $reference)) {
                return $entry;
            }
        }

        return null;
    }
}
