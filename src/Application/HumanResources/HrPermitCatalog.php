<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Immutable fictional permits: never reads employees, HR records, or balances. */
final class HrPermitCatalog
{
    private const EXAMPLES = [
        'personal' => ['Permiso personal ficticio', '2030-01-10'],
        'tramite' => ['Trámite ficticio', '2030-02-12'],
        'familiar' => ['Permiso familiar ficticio', '2030-03-15'],
    ];

    /** @return list<array{ref:string,label:string,starts_on:string,ends_on:string,state:string,synthetic:bool,automatically_approved:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $prefix = $this->scopePrefix($tenant, $company, $branch);
        $examples = [];
        foreach (self::EXAMPLES as $slug => [$description, $date]) {
            $examples[] = self::example($prefix, $slug, $description, $date);
        }
        return $examples;
    }

    /** @return array{ref:string,label:string,starts_on:string,ends_on:string,state:string,synthetic:bool,automatically_approved:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $prefix = $this->scopePrefix($tenant, $company, $branch);
        if (strlen($ref) > 80 || !preg_match('/\Afixture-permit-[0-9a-f]{16}-[a-z]+\z/D', $ref)) {
            return null;
        }
        foreach (self::EXAMPLES as $slug => [$description, $date]) {
            $candidate = self::example($prefix, $slug, $description, $date);
            if (hash_equals($candidate['ref'], $ref)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return array{ref:string,label:string,starts_on:string,ends_on:string,state:string,synthetic:bool,automatically_approved:bool} */
    private static function example(string $prefix, string $slug, string $description, string $date): array
    {
        return [
            'ref' => 'fixture-permit-'.$prefix.'-'.$slug,
            'label' => $description,
            'starts_on' => $date,
            'ends_on' => $date,
            'state' => 'PENDIENTE',
            'synthetic' => true,
            'automatically_approved' => false,
        ];
    }

    private function scopePrefix(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        $tenantKey = $tenant->id();
        if ($company->tenant()->id() !== $tenantKey || $branch->tenant()->id() !== $tenantKey) {
            throw new DomainException('Tenant ficticio inválido para el catálogo de permisos.');
        }
        if ($branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Sede ficticia fuera de su empresa.');
        }
        return substr(hash('sha256', $tenantKey.'|'.$company->id().'|'.$branch->id()), 0, 16);
    }
}
