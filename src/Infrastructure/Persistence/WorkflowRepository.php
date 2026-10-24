<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Workflow\ExecutionTrace;
use App\Domain\Workflow\WorkflowDefinition;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use JsonException;

/**
 * Almacén tenant-safe de definiciones inmutables y resultados explícitos.
 * No ejecuta acciones, y nunca persiste argumentos ni payloads de eventos.
 */
final readonly class WorkflowRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(
        WorkflowDefinition $definition,
        string $tenantId,
        string $actorId,
        DateTimeImmutable $now,
    ): int {
        self::identity($tenantId, 26);
        self::identity($actorId, 26);
        if ($definition->tenantId !== $tenantId) {
            throw new DomainException('La definición pertenece a otro tenant.');
        }
        // La definición es configuración, pero nunca admite argumentos de
        // acciones: podrían contener datos personales o secretos del disparador.
        // Rechazar en vez de truncar silenciosamente el workflow del caller.
        foreach ($definition->actions as $action) {
            if (array_keys($action) !== ['type']) {
                throw new DomainException('La persistencia rechaza argumentos y metadatos de acciones.');
            }
        }
        $time = self::utc($now);

        return (int) $this->connection->transactional(function () use ($definition, $tenantId, $actorId, $time): int {
            $version = 1 + (int) $this->connection->fetchOne(
                'SELECT COALESCE(MAX(version), 0) FROM condor_workflow_definition WHERE tenant_id = ? AND workflow_id = ?',
                [$tenantId, $definition->workflowId],
            );
            $this->connection->executeStatement(
                'UPDATE condor_workflow_definition SET is_active = 0, updated_at = ?, updated_by_user_id = ? WHERE tenant_id = ? AND workflow_id = ? AND is_active = 1',
                [$time, $actorId, $tenantId, $definition->workflowId],
            );
            $this->connection->insert('condor_workflow_definition', [
                'tenant_id' => $tenantId,
                'workflow_id' => $definition->workflowId,
                'version' => $version,
                'trigger_name' => $definition->trigger,
                'conditions_json' => json_encode($definition->conditions, JSON_THROW_ON_ERROR),
                'actions_json' => json_encode($definition->actions, JSON_THROW_ON_ERROR),
                'is_active' => (int) $definition->active,
                'created_by_user_id' => $actorId,
                'updated_by_user_id' => $actorId,
                'created_at' => $time,
                'updated_at' => $time,
            ]);

            return $version;
        });
    }

    /** @return array{definition: WorkflowDefinition, version: int}|null */
    public function latest(string $tenantId, string $workflowId): ?array
    {
        self::identity($tenantId, 26);
        self::identity($workflowId, 128);
        $row = $this->connection->fetchAssociative(
            'SELECT tenant_id, workflow_id, version, trigger_name, conditions_json, actions_json, is_active FROM condor_workflow_definition WHERE tenant_id = ? AND workflow_id = ? ORDER BY version DESC LIMIT 1',
            [$tenantId, $workflowId],
        );
        if ($row === false) {
            return null;
        }
        try {
            $conditions = json_decode((string) $row['conditions_json'], true, 32, JSON_THROW_ON_ERROR);
            $actions = json_decode((string) $row['actions_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($conditions) || !array_is_list($conditions) || !is_array($actions) || !array_is_list($actions)) {
                throw new DomainException('Definición persistida inválida.');
            }
            return [
                'definition' => new WorkflowDefinition(
                    (string) $row['tenant_id'],
                    (string) $row['workflow_id'],
                    (string) $row['trigger_name'],
                    $conditions,
                    $actions,
                    (int) $row['is_active'] === 1,
                ),
                'version' => (int) $row['version'],
            ];
        } catch (JsonException | \TypeError $error) {
            throw new DomainException('Definición persistida inválida.', previous: $error);
        }
    }

    public function setActive(
        string $tenantId,
        string $workflowId,
        int $expectedVersion,
        bool $active,
        string $actorId,
        DateTimeImmutable $now,
    ): void {
        self::identity($tenantId, 26);
        self::identity($workflowId, 128);
        self::identity($actorId, 26);
        if ($expectedVersion < 1) {
            throw new DomainException('Versión esperada inválida.');
        }
        $this->connection->transactional(function () use ($tenantId, $workflowId, $expectedVersion, $active, $actorId, $now): void {
            $current = $this->latest($tenantId, $workflowId);
            if ($current === null || $current['version'] !== $expectedVersion) {
                throw new DomainException('Conflicto de versión del workflow.');
            }
            $this->connection->executeStatement(
                'UPDATE condor_workflow_definition SET is_active = ?, updated_by_user_id = ?, updated_at = ? WHERE tenant_id = ? AND workflow_id = ? AND version = ?',
                [(int) $active, $actorId, self::utc($now), $tenantId, $workflowId, $expectedVersion],
            );
        });
    }

    /**
     * Registra el resultado emitido por un ejecutor autorizado, no un plan
     * como si fuera una ejecución. Nunca copia el payload del disparador.
     */
    public function recordExecution(
        ExecutionTrace $trace,
        string $tenantId,
        string $actorId,
        string $result,
        DateTimeImmutable $now,
    ): string {
        self::identity($tenantId, 26);
        self::identity($actorId, 26);
        if ($trace->tenantId !== $tenantId
            || !in_array($result, ['completed', 'failed', 'skipped'], true)
            || ($trace->status !== 'planned' && $result !== 'skipped')
            || ($trace->status === 'planned' && $result === 'skipped')
            || preg_match('/^[a-z_]{1,40}$/D', $trace->reason) !== 1) {
            throw new DomainException('Resultado o tenant de ejecución inválido.');
        }
        return (string) $this->connection->transactional(function () use ($trace, $tenantId, $actorId, $result, $now): string {
            $latest = $this->latest($tenantId, $trace->workflowId);
            if ($latest === null || ($result !== 'skipped' && !$latest['definition']->active)) {
                throw new DomainException('No se ejecuta un workflow inexistente o desactivado.');
            }
            $id = bin2hex(random_bytes(16));
            $this->connection->insert('condor_workflow_execution', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'workflow_id' => $trace->workflowId,
                'definition_version' => $latest['version'],
                'actor_user_id' => $actorId,
                'result' => $result,
                'reason' => $trace->reason,
                'created_at' => self::utc($now),
            ]);

            return $id;
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(string $tenantId, string $workflowId, int $limit = 50): array
    {
        self::identity($tenantId, 26);
        self::identity($workflowId, 128);
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Límite de auditoría inválido.');
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, workflow_id, definition_version, actor_user_id, result, reason, created_at FROM condor_workflow_execution WHERE tenant_id = ? AND workflow_id = ? ORDER BY created_at DESC, id DESC LIMIT '.$limit,
            [$tenantId, $workflowId],
        );
    }

    private static function identity(string $value, int $max): void
    {
        if (strlen($value) < 1 || strlen($value) > $max || preg_match('/^[a-zA-Z0-9_.:-]+$/D', $value) !== 1) {
            throw new DomainException('Identidad no válida para persistencia.');
        }
    }

    private static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
