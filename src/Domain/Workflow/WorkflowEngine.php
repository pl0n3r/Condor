<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use DomainException;

/**
 * Tenant-scoped rule evaluator. Emits a plan, never performs side effects.
 * Every action type must be registered by its owning internal module.
 */
final readonly class WorkflowEngine
{
    /** @param array<string, string> $registeredActions action type => required permission */
    public function __construct(private array $registeredActions)
    {
        foreach ($registeredActions as $type => $permission) {
            if (!is_string($type) || !is_string($permission)
                || preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/D', $type) !== 1
                || trim($permission) === '') {
                throw new DomainException('Registro de acciones inválido.');
            }
        }
    }

    /**
     * @param array<string, mixed> $eventPayload synthetic values, never logged
     * @param list<string> $permissions permissions of the authenticated actor
     */
    public function evaluate(
        WorkflowDefinition $definition,
        string $tenantId,
        string $eventType,
        array $eventPayload,
        array $permissions,
    ): ExecutionTrace {
        if ($tenantId === '' || $tenantId !== $definition->tenantId) {
            throw new DomainException('El workflow no pertenece al tenant activo.');
        }

        // Fail closed even when disabled or the event does not match.
        foreach ($definition->actions as $action) {
            $type = $action['type'];
            if (!array_key_exists($type, $this->registeredActions)) {
                throw new DomainException('Acción interna no registrada: '.$type);
            }
            $permission = $this->registeredActions[$type];
            if (!in_array($permission, $permissions, true)) {
                throw new DomainException('Permiso insuficiente para la acción: '.$type);
            }
        }

        if (!$definition->active || $eventType !== $definition->trigger) {
            return new ExecutionTrace(
                $tenantId,
                $definition->workflowId,
                'skipped',
                !$definition->active ? 'inactive' : 'trigger_mismatch',
                [],
                [],
            );
        }

        $checks = [];
        $eligible = true;
        foreach ($definition->conditions as $condition) {
            [$found, $actual] = self::lookup($eventPayload, $condition['field']);
            $matched = match ($condition['operator']) {
                'eq' => $found && $actual === $condition['value'],
                'neq' => $found && $actual !== $condition['value'],
                'in' => $found && in_array($actual, $condition['value'], true),
                'exists' => $found && $actual !== null,
            };
            $checks[] = ['field' => $condition['field'], 'matched' => $matched];
            if (!$matched) {
                $eligible = false;
            }
        }

        return new ExecutionTrace(
            $tenantId,
            $definition->workflowId,
            $eligible ? 'planned' : 'skipped',
            $eligible ? 'conditions_matched' : 'conditions_not_matched',
            $checks,
            $eligible ? array_column($definition->actions, 'type') : [],
        );
    }

    /** @param array<string, mixed> $payload @return array{bool, mixed} */
    private static function lookup(array $payload, string $field): array
    {
        $cursor = $payload;
        foreach (explode('.', $field) as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$key];
        }

        return [true, $cursor];
    }
}
