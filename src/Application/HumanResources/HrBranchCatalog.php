<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Fictional, immutable branch samples: no staff data and no persistence. */
final class HrBranchCatalog
{
    private const LABELS = [
        'norte' => 'Sede de ensayo Norte',
        'centro' => 'Sede de ensayo Centro',
        'sur' => 'Sede de ensayo Sur',
    ];

    /** @return list<array{ref:string,label:string,kind:string,synthetic:bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $scope = self::scopeToken($tenant, $company, $branch);
        $samples = [];
        foreach (self::LABELS as $suffix => $label) {
            $samples[] = [
                'ref' => sprintf('fixture-branch-%s-%s', $scope, $suffix),
                'label' => $label,
                'kind' => 'ensayo',
                'synthetic' => true,
            ];
        }

        return $samples;
    }

    /** @return array{ref:string,label:string,kind:string,synthetic:bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $samples = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || preg_match('/\Afixture-branch-[a-f0-9]{16}-[a-z]+\z/D', $ref) !== 1) {
            return null;
        }

        foreach ($samples as $sample) {
            if (hash_equals($sample['ref'], $ref)) {
                return $sample;
            }
        }

        return null;
    }

    private static function scopeToken(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        $tenantId = $tenant->id();
        if ($branch->tenant()->id() !== $tenantId) {
            throw new DomainException('Sede fuera del tenant sintético.');
        }
        if ($company->tenant()->id() !== $tenantId) {
            throw new DomainException('Empresa fuera del tenant sintético.');
        }
        if ($branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Sede fuera de la empresa sintética.');
        }

        return substr(hash('sha256', $tenantId."\0".$company->id()."\0".$branch->id()), 0, 16);
    }
}
