<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Pure fictional evaluation cases; never consult or store real personnel. */
final class HrEvaluationCatalog
{
    private const CASES = [
        ['tag' => 'auto-inicial', 'kind' => 'AUTOEVALUACION', 'state' => 'BORRADOR'],
        ['tag' => 'auto-revision', 'kind' => 'AUTOEVALUACION', 'state' => 'EN_REVISION'],
        ['tag' => 'jefe-revision', 'kind' => 'JEFE', 'state' => 'EN_REVISION'],
    ];

    /**
     * @return list<array{ref: string, cycle_ref: string, employee_ref: string,
     * evaluator_ref: string, kind: string, state: string, score: null,
     * needs_human_review: bool, automatically_approved: bool,
     * automatically_closed: bool, synthetic: bool}>
     */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        $scope = self::scopeFingerprint($tenant, $company, $branch);
        $result = [];
        $cycle = 'fixture:cycle/ensayo-'.substr(hash('sha256', 'cycle|'.$scope), 0, 12);

        foreach (self::CASES as $case) {
            $label = $case['tag'];
            $person = 'fixture:employee/evaluado-'.substr(
                hash('sha256', 'person|'.$scope.'|'.$label), 0, 12,
            );
            $reviewer = $case['kind'] === 'AUTOEVALUACION'
                ? $person
                : 'fixture:employee/revisor-'.substr(
                    hash('sha256', 'reviewer|'.$scope.'|'.$label), 0, 12,
                );

            $result[] = [
                'ref' => 'fixture-evaluation-'.$scope.'-'.$label,
                'cycle_ref' => $cycle,
                'employee_ref' => $person,
                'evaluator_ref' => $reviewer,
                'kind' => $case['kind'],
                'state' => $case['state'],
                'score' => null,
                'needs_human_review' => true,
                'automatically_approved' => false,
                'automatically_closed' => false,
                'synthetic' => true,
            ];
        }

        return $result;
    }

    /**
     * @return array{ref: string, cycle_ref: string, employee_ref: string,
     * evaluator_ref: string, kind: string, state: string, score: null,
     * needs_human_review: bool, automatically_approved: bool,
     * automatically_closed: bool, synthetic: bool}|null
     */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        $catalog = $this->listFor($tenant, $company, $branch);
        if ($ref === '' || strlen($ref) > 80) {
            return null;
        }

        foreach ($catalog as $entry) {
            if (hash_equals($entry['ref'], $ref)) {
                return $entry;
            }
        }

        return null;
    }

    private static function scopeFingerprint(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        $tenantId = $tenant->id();
        if ($company->tenant()->id() !== $tenantId) {
            throw new DomainException('Razón social fuera del tenant autorizado.');
        }
        if ($branch->tenant()->id() !== $tenantId || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Sede fuera del alcance de evaluaciones ficticias.');
        }

        // Only the authorized organization IDs and fixed demo tags enter the
        // opaque fixture references; there is no employee repository lookup.
        return substr(hash('sha256', implode("\0", [
            $tenantId, $company->id(), $branch->id(),
        ])), 0, 16);
    }
}
