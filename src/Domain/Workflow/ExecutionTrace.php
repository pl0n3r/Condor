<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

/**
 * Pure, deterministic explanation of a plan, never evidence of execution.
 * Deliberately excludes event payloads and action arguments.
 */
final readonly class ExecutionTrace
{
    /**
     * @param list<array{field: string, matched: bool}> $conditions
     * @param list<string> $plannedActions
     */
    public function __construct(
        public string $tenantId,
        public string $workflowId,
        public string $status,
        public string $reason,
        public array $conditions,
        public array $plannedActions,
    ) {
    }

    /**
     * @return array{tenant_id: string, workflow_id: string, status: string,
     *               reason: string, conditions: list<array{field: string, matched: bool}>,
     *               planned_actions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'workflow_id' => $this->workflowId,
            'status' => $this->status,
            'reason' => $this->reason,
            'conditions' => $this->conditions,
            'planned_actions' => $this->plannedActions,
        ];
    }
}
