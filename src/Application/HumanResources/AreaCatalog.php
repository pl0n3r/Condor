<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Scope-bound fictional areas. No repository, person, contract or write path. */
final class AreaCatalog
{
    /** @var array<string,string> */
    private const FIXTURE_NAMES = [
        'operations' => 'Área ficticia de operaciones',
        'assistance' => 'Área ficticia de soporte',
        'review' => 'Área ficticia de calidad',
    ];

    /** @return list<array{ref: string, label: string, synthetic: bool}> */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $this->verifyOwnership($tenant, $company, $branch);

        $rows = [];
        foreach (self::FIXTURE_NAMES as $code => $label) {
            $rows[] = [
                'ref' => $this->reference($tenant, $company, $branch, $code),
                'label' => $label,
                'synthetic' => true,
            ];
        }

        return $rows;
    }

    /** @return array{ref: string, label: string, synthetic: bool}|null */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $reference): ?array
    {
        // Invalid references still undergo authorization of all three boundaries.
        $rows = $this->listFor($tenant, $company, $branch);
        if (preg_match('/\Afixture-area-[a-f0-9]{24}\z/D', $reference) !== 1) {
            return null;
        }

        foreach ($rows as $row) {
            if (hash_equals($row['ref'], $reference)) {
                return $row;
            }
        }

        return null;
    }

    private function verifyOwnership(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        $correctTenant = $company->tenant()->id() === $tenant->id();
        $correctBranch = $branch->tenant()->id() === $tenant->id();
        $correctCompany = $branch->legalEntity()?->id() === $company->id();

        if (!$correctTenant || !$correctBranch || !$correctCompany) {
            throw new DomainException('Áreas de otro contexto no disponibles.');
        }
    }

    private function reference(Tenant $tenant, LegalEntity $company, Branch $branch, string $code): string
    {
        // The opaque reference does not expose the tenant, company, branch or label.
        $identity = json_encode([$tenant->id(), $company->id(), $branch->id(), $code], JSON_THROW_ON_ERROR);
        return 'fixture-area-'.substr(hash('sha256', $identity), 0, 24);
    }
}
