<?php

declare(strict_types=1);

namespace App\Application\HumanResources;

use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/**
 * Read-only, deterministic fictional evaluations, never a source of real
 * personnel records, ratings, rankings, approvals or HR decisions.
 */
final class HrEvaluationCatalog
{
    private const EVALUATIONS = [
        ['slug' => 'auto-borrador', 'kind' => 'AUTOEVALUACION', 'state' => 'BORRADOR'],
        ['slug' => 'auto-revision', 'kind' => 'AUTOEVALUACION', 'state' => 'EN_REVISION'],
        ['slug' => 'jefe-revision', 'kind' => 'JEFE', 'state' => 'EN_REVISION'],
    ];

    /**
     * @return list<array{ref: string, cycle_ref: string, employee_ref: string,
     *     evaluator_ref: string, kind: string, state: string, score: null,
     *     needs_human_review: bool, automatically_approved: bool,
     *     automatically_closed: bool, synthetic: bool}>
     */
    public function listFor(Tenant $tenant, LegalEntity $company, Branch $branch): array
    {
        self::assertScope($tenant, $company, $branch);
        $scope = self::scopeRef($tenant, $company, $branch);
        $cycleRef = 'fixture:cycle/ensayo-'.substr(hash('sha256', $scope.'|cycle'), 0, 12);
        $rows = [];
        foreach (self::EVALUATIONS as $fixture) {
            $slug = $fixture['slug'];
            // Identifiers are derived solely from the authorized scope and
            // fixed synthetic slugs; no employee repository is ever queried.
            $employeeRef = 'fixture:employee/ensayo-'.substr(
                hash('sha256', $scope.'|'.$slug.'|employee'), 0, 12,
            );
            $evaluatorRef = $fixture['kind'] === 'AUTOEVALUACION'
                ? $employeeRef
                : 'fixture:employee/revisor-'.substr(
                    hash('sha256', $scope.'|'.$slug.'|evaluator'), 0, 12,
                );
            $rows[] = [
                'ref' => 'fixture-evaluation-'.$scope.'-'.$slug,
                'cycle_ref' => $cycleRef,
                'employee_ref' => $employeeRef,
                'evaluator_ref' => $evaluatorRef,
                'kind' => $fixture['kind'],
                'state' => $fixture['state'],
                'score' => null,
                'needs_human_review' => true,
                'automatically_approved' => false,
                'automatically_closed' => false,
                'synthetic' => true,
            ];
        }

        return $rows;
    }

    /**
     * @return array{ref: string, cycle_ref: string, employee_ref: string,
     *     evaluator_ref: string, kind: string, state: string, score: null,
     *     needs_human_review: bool, automatically_approved: bool,
     *     automatically_closed: bool, synthetic: bool}|null
     */
    public function findFor(Tenant $tenant, LegalEntity $company, Branch $branch, string $ref): ?array
    {
        // Revalidate the entire scope before resolving an opaque fixture ref.
        $rows = $this->listFor($tenant, $company, $branch);
        if (strlen($ref) > 80 || preg_match('/\Afixture-evaluation-[a-f0-9]{16}-[a-z-]+\z/D', $ref) !== 1) {
            return null;
        }
        foreach ($rows as $row) {
            if (hash_equals($row['ref'], $ref)) {
                return $row;
            }
        }

        return null;
    }

    private static function assertScope(Tenant $tenant, LegalEntity $company, Branch $branch): void
    {
        if ($company->tenant()->id() !== $tenant->id()
            || $branch->tenant()->id() !== $tenant->id()
            || $branch->legalEntity()?->id() !== $company->id()) {
            throw new DomainException('Contexto de evaluaciones ficticias no disponible.');
        }
    }

    private static function scopeRef(Tenant $tenant, LegalEntity $company, Branch $branch): string
    {
        return substr(hash('sha256', implode('|', [
            $tenant->id(), $company->id(), $branch->id(),
        ])), 0, 16);
    }
}
