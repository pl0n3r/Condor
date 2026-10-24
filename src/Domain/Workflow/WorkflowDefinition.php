<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use DomainException;

/**
 * Immutable, tenant-bound workflow declaration. No persistence or dispatch.
 */
final readonly class WorkflowDefinition
{
    /**
     * @param list<array{field: string, operator: string, value?: mixed}> $conditions
     * @param list<array{type: string, arguments?: array<string, scalar|null>}> $actions
     */
    public function __construct(
        public string $tenantId,
        public string $workflowId,
        public string $trigger,
        public array $conditions,
        public array $actions,
        public bool $active = true,
    ) {
        foreach ([$tenantId, $workflowId, $trigger] as $identity) {
            if (trim($identity) === '' || strlen($identity) > 128) {
                throw new DomainException('La identidad del workflow es inválida.');
            }
        }
        if ($actions === []) {
            throw new DomainException('Un workflow requiere acciones explícitas.');
        }
        foreach ($conditions as $condition) {
            if (!is_array($condition) || !isset($condition['field'], $condition['operator'])
                || !is_string($condition['field']) || !is_string($condition['operator'])
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $condition['field']) !== 1
                || !in_array($condition['operator'], ['eq', 'neq', 'in', 'exists'], true)
            ) {
                throw new DomainException('Condición de workflow no permitida.');
            }
            if ($condition['operator'] !== 'exists' && !array_key_exists('value', $condition)) {
                throw new DomainException('La condición necesita un valor.');
            }
            if ($condition['operator'] === 'in'
                && (!is_array($condition['value']) || !array_is_list($condition['value']))) {
                throw new DomainException('La condición in necesita una lista.');
            }
        }
        foreach ($actions as $action) {
            if (!is_array($action) || !isset($action['type']) || !is_string($action['type'])
                || preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/D', $action['type']) !== 1
                || (isset($action['arguments']) && !is_array($action['arguments']))) {
                throw new DomainException('Acción de workflow no permitida.');
            }
        }
    }
}
