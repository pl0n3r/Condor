<?php

declare(strict_types=1);

namespace App\Application\Observability;

/**
 * Puerta de release: todo GAP-P0 del registro debe enlazar un Issue y un
 * runbook existente. Falla cerrado ante registro vacío o ilegible.
 */
final class CapabilityReleaseGate
{
    public const REGISTRY = 'docs/architecture/capability-registry.md';

    /** @return list<string> violaciones; vacío significa que el release puede continuar */
    public static function violations(string $projectDir): array
    {
        $registry = @file_get_contents($projectDir.'/'.self::REGISTRY);
        if (!is_string($registry)) {
            return ['registro ilegible: '.self::REGISTRY];
        }

        return self::evaluate($registry, $projectDir);
    }

    /** @return list<string> */
    public static function evaluate(string $registry, string $projectDir): array
    {
        $violations = [];
        $rows = 0;
        foreach (preg_split('/\R/', $registry) ?: [] as $line) {
            if (preg_match('/^\|\s*(GAP-P0-[A-Za-z0-9-]+)\s*\|(.*)\|\s*$/', $line, $m) !== 1) {
                continue;
            }
            ++$rows;
            $cells = array_map('trim', explode('|', $m[2]));
            $issue = $cells[1] ?? '';
            $runbook = $cells[2] ?? '';
            if (preg_match('/^#[1-9][0-9]{0,8}$/D', $issue) !== 1) {
                $violations[] = $m[1].': sin Issue enlazado';
            }
            if (
                preg_match('#^docs/runbooks/[a-z0-9-]+\.md$#D', $runbook) !== 1
                || !is_file($projectDir.'/'.$runbook)
            ) {
                $violations[] = $m[1].': sin runbook verificable';
            }
        }
        if ($rows === 0) {
            $violations[] = 'registro sin GAP-P0: no se puede verificar';
        }

        return $violations;
    }
}
