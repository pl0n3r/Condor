<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Workflow\WorkflowDefinition;
use App\Domain\Workflow\WorkflowEngine;
use App\Infrastructure\Persistence\WorkflowRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A management-only interface to a pure workflow planner and versioned store.
 * No endpoint dispatches an action or writes an execution as a side effect of a dry run.
 */
final class WorkflowController extends AbstractController
{
    use BranchApiSupport;

    /**
     * Catalog from the initial internal workflow engine (#855).
     * Each value is an existing permission; no unregistered or external actions.
     * @var array<string, string>
     */
    private const ACTIONS = [
        'inventory.restock' => 'inventory.update',
        'notifications.queue' => 'settings.update',
    ];

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflows,
        private readonly Connection $connection,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/workflows', name: 'api_workflows_index', methods: ['GET'])]
    public function index(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'settings.update');
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT workflow_id FROM condor_workflow_definition WHERE tenant_id = ? ORDER BY workflow_id LIMIT 100',
            [$tenant->id()],
        );
        $items = [];
        foreach ($ids as $id) {
            $latest = $this->workflows->latest($tenant->id(), (string) $id);
            if ($latest !== null) {
                $items[] = self::description($latest);
            }
        }
        return $this->json(['workflows' => $items]);
    }

    #[Route('/api/v1/branches/{branchId}/workflows/action-catalog', name: 'api_workflows_actions', methods: ['GET'])]
    public function actions(string $branchId): JsonResponse
    {
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $permissions = $this->authorization->permissions($user, $tenant, $branch);
        return $this->json(['actions' => array_keys(array_filter(
            self::ACTIONS,
            static fn (string $permission): bool => in_array($permission, $permissions, true),
        ))]);
    }

    #[Route('/api/v1/branches/{branchId}/workflows', name: 'api_workflows_create', methods: ['POST'])]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->requireSmallBody($request);
        $data = $this->payload($request, ['workflow_id', 'trigger', 'conditions', 'actions', 'active']);
        $id = $this->requiredString($data, 'workflow_id');
        $created = $this->withWorkflowLock($tenant->id(), function () use ($data, $id, $user, $tenant, $branch): array {
            if ($this->domain(fn (): ?array => $this->workflows->latest($tenant->id(), $id)) !== null) {
                throw new ConflictHttpException('Workflow ya registrado.');
            }
            $definition = $this->validatedDefinition($data, $tenant->id(), $id, $user, $tenant, $branch);
            $version = $this->domain(fn (): int => $this->workflows->save(
                $definition, $tenant->id(), $user->id(), new DateTimeImmutable('now'),
            ));
            $this->auditWorkflow($tenant, $user, $branch->id(), $id, 'workflow.created', $version);

            return ['definition' => $definition, 'version' => $version];
        });

        return $this->json(['workflow' => self::description($created)], Response::HTTP_CREATED);
    }

        #[Route('/api/v1/branches/{branchId}/workflows/{workflowId}', name: 'api_workflows_show', methods: ['GET'])]
    public function show(string $branchId, string $workflowId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'settings.update');
        return $this->json(['workflow' => self::description($this->existing($tenant->id(), $workflowId))]);
    }

    #[Route('/api/v1/branches/{branchId}/workflows/{workflowId}', name: 'api_workflows_update', methods: ['PUT'])]
    public function update(string $branchId, string $workflowId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->requireSmallBody($request);
        $data = $this->payload($request, ['expected_version', 'trigger', 'conditions', 'actions', 'active']);
        $updated = $this->withWorkflowLock($tenant->id(), function () use ($data, $workflowId, $user, $tenant, $branch): array {
            $current = $this->existing($tenant->id(), $workflowId);
            if (!is_int($data['expected_version'] ?? null) || $data['expected_version'] !== $current['version']) {
                throw new ConflictHttpException('Versión esperada inválida o desactualizada.');
            }
            $definition = $this->validatedDefinition($data, $tenant->id(), $workflowId, $user, $tenant, $branch);
            $version = $this->domain(fn (): int => $this->workflows->save(
                $definition, $tenant->id(), $user->id(), new DateTimeImmutable('now'),
            ));
            $this->auditWorkflow($tenant, $user, $branch->id(), $workflowId, 'workflow.updated', $version);

            return ['definition' => $definition, 'version' => $version];
        });

        return $this->json(['workflow' => self::description($updated)]);
    }

        /** Soft-delete: deactivation retains prior definitions and execution history for audit. */
    #[Route('/api/v1/branches/{branchId}/workflows/{workflowId}', name: 'api_workflows_delete', methods: ['DELETE'])]
    public function delete(string $branchId, string $workflowId, Request $request): Response
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->requireSmallBody($request);
        $data = $this->payload($request, ['expected_version']);
        $this->withWorkflowLock($tenant->id(), function () use ($data, $workflowId, $user, $tenant, $branch): void {
            $current = $this->existing($tenant->id(), $workflowId);
            if (!is_int($data['expected_version'] ?? null) || $data['expected_version'] !== $current['version']) {
                throw new ConflictHttpException('Versión esperada inválida o desactualizada.');
            }
            $this->domain(fn (): null => $this->deactivate($tenant->id(), $workflowId, $current['version'], $user->id()));
            $this->auditWorkflow($tenant, $user, $branch->id(), $workflowId, 'workflow.deactivated', $current['version']);
        });

        return new Response('', Response::HTTP_NO_CONTENT);
    }

        #[Route('/api/v1/branches/{branchId}/workflows/{workflowId}/history', name: 'api_workflows_history', methods: ['GET'])]
    public function history(string $branchId, string $workflowId, Request $request): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->existing($tenant->id(), $workflowId);
        $limit = $request->query->get('limit', '50');
        if (preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $limit) !== 1) {
            throw new UnprocessableEntityHttpException('Límite de historial inválido.');
        }
        $history = $this->domain(fn (): array => $this->workflows->history($tenant->id(), $workflowId, (int) $limit));
        // No event payload or action argument is serialized by the repository.
        return $this->json(['executions' => $history]);
    }

    #[Route('/api/v1/branches/{branchId}/workflows/{workflowId}/dry-run', name: 'api_workflows_dry_run', methods: ['POST'])]
    public function dryRun(string $branchId, string $workflowId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        $this->requireSmallBody($request);
        $data = $this->payload($request, ['event_type', 'event']);
        $eventType = $this->requiredString($data, 'event_type');
        $rawEvent = $data['event'] ?? null;
        if (!$rawEvent instanceof \stdClass) {
            throw new UnprocessableEntityHttpException('Evento sintético inválido o excesivo.');
        }
        /** @var array<string, mixed> $event */
        $event = self::jsonToArray($rawEvent);
        $current = $this->existing($tenant->id(), $workflowId);
        $permissions = $this->authorization->permissions($user, $tenant, $branch);
        $trace = $this->domain(fn (): array => (new WorkflowEngine(self::ACTIONS))->evaluate(
            $current['definition'], $tenant->id(), $eventType, $event, $permissions,
        )->toArray());
        return $this->json(['trace' => $trace, 'persisted' => false]);
    }

    private function requireSmallBody(Request $request): void
    {
        if (strlen((string) $request->getContent()) > 8192) {
            throw new UnprocessableEntityHttpException('Cuerpo JSON demasiado extenso.');
        }
    }

    /**
     * Every API mutation locks the durable tenant row before reading the latest
     * workflow version, including the first insert with no workflow row to lock.
     * The DBAL write and ORM audit flush share the same database transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withWorkflowLock(string $tenantId, callable $operation): mixed
    {
        return $this->connection->transactional(function () use ($tenantId, $operation): mixed {
            $id = $this->connection->fetchOne(
                'SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE',
                [$tenantId],
            );
            if ($id === false) {
                throw new ConflictHttpException('Tenant no disponible para escritura.');
            }

            return $operation();
        });
    }

    private function auditWorkflow(
        \App\Domain\Organization\Entity\Tenant $tenant,
        \App\Domain\Identity\Entity\User $user,
        string $branchId,
        string $workflowId,
        string $action,
        int $version,
    ): void {
        // AuditEvent.entity_id is limited to 64 chars; hash avoids truncating
        // workflow IDs (up to 128) and keeps synthetic/event payloads out.
        $this->audit(
            $tenant,
            $user,
            $action,
            WorkflowDefinition::class,
            hash('sha256', $tenant->id().':'.$workflowId),
            ['branch_id' => $branchId, 'version' => $version],
        );
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $payload */
    private function validatedDefinition(
        array $payload,
        string $tenantId,
        string $workflowId,
        \App\Domain\Identity\Entity\User $user,
        \App\Domain\Organization\Entity\Tenant $tenant,
        \App\Domain\Organization\Entity\Branch $branch,
    ): WorkflowDefinition {
        $trigger = $this->requiredString($payload, 'trigger');
        $conditions = self::jsonToArray($payload['conditions'] ?? null);
        $actions = self::jsonToArray($payload['actions'] ?? null);
        $active = $payload['active'] ?? true;
        if (!is_array($conditions) || !array_is_list($conditions) || count($conditions) > 20
            || !is_array($actions) || !array_is_list($actions) || count($actions) > 10
            || !is_bool($active)) {
            throw new UnprocessableEntityHttpException('Definición de workflow inválida.');
        }
        foreach ($conditions as $condition) {
            if (!is_array($condition) || !is_string($condition['field'] ?? null)
                || !is_string($condition['operator'] ?? null)
                || array_diff(array_keys($condition), ['field', 'operator', 'value']) !== []) {
                throw new UnprocessableEntityHttpException('Condición no permitida.');
            }
        }
        foreach ($actions as $action) {
            if (!is_array($action) || array_keys($action) !== ['type'] || !is_string($action['type'])) {
                throw new UnprocessableEntityHttpException('Acción no permitida.');
            }
        }
        return $this->domain(function () use ($tenantId, $workflowId, $trigger, $conditions, $actions, $active, $user, $tenant, $branch): WorkflowDefinition {
            $definition = new WorkflowDefinition($tenantId, $workflowId, $trigger, $conditions, $actions, $active);
            $permissions = $this->authorization->permissions($user, $tenant, $branch);
            // Even disabled definitions cannot register unknown or unauthorized actions.
            (new WorkflowEngine(self::ACTIONS))->evaluate($definition, $tenantId, '', [], $permissions);
            return $definition;
        });
    }

    /** @return array{definition: WorkflowDefinition, version: int} */
    private function existing(string $tenantId, string $workflowId): array
    {
        try {
            $current = $this->workflows->latest($tenantId, $workflowId);
        } catch (DomainException $exception) {
            throw new NotFoundHttpException('Workflow no encontrado.', $exception);
        }
        if ($current === null) {
            throw new NotFoundHttpException('Workflow no encontrado.');
        }
        return $current;
    }

    private function deactivate(string $tenantId, string $workflowId, int $version, string $actorId): null
    {
        $this->workflows->setActive($tenantId, $workflowId, $version, false, $actorId, new DateTimeImmutable('now'));
        return null;
    }

    /**
     * @param array{definition: WorkflowDefinition, version: int} $latest
     * @return array{workflow_id: string, trigger: string, conditions: list<array{field: string, operator: string, value?: mixed}>, actions: list<array{type: string, arguments?: array<string, scalar|null>}>, active: bool, version: int}
     */
    private static function description(array $latest): array
    {
        $def = $latest['definition'];
        return [
            'workflow_id' => $def->workflowId,
            'trigger' => $def->trigger,
            'conditions' => $def->conditions,
            'actions' => $def->actions,
            'active' => $def->active,
            'version' => $latest['version'],
        ];
    }

    /** The shared HTTP payload decoder keeps nested JSON objects as stdClass. */
    private static function jsonToArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $child) {
                $result[$key] = self::jsonToArray($child);
            }
            return $result;
        }
        if (is_array($value)) {
            return array_map(self::jsonToArray(...), $value);
        }
        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 128) {
            throw new UnprocessableEntityHttpException('Campo requerido inválido: '.$field);
        }
        return $value;
    }
}
