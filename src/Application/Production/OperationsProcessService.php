<?php
declare(strict_types=1);
namespace App\Application\Production;
use DomainException;
/** Pure M11 rehearsal: no database, external action, execution or audit mutation. */
final class OperationsProcessService
{
    private const PROCESSES = [[
        'id' => 'fixture:process/recepcion',
        'name' => 'Recepción ficticia',
        'procedures' => [[
            'id' => 'fixture:procedure/inspeccion',
            'name' => 'Inspección ficticia',
            'tasks' => [
                ['id' => 'fixture:task/comprobar', 'name' => 'Comprobar lista ficticia'],
                ['id' => 'fixture:task/registrar', 'name' => 'Simular registro'],
            ],
        ]],
    ]];
    /** @return list<array<string,mixed>> */
    public static function catalog(): array
    {
        return self::PROCESSES;
    }
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function preview(array $input): array
    {
        $fields = ['process_id', 'procedure_id', 'task_id', 'state'];
        if (count($input) !== 4 || array_diff(array_keys($input), $fields) !== []) {
            throw new DomainException('Campos de simulación inválidos.');
        }
        foreach ($fields as $field) {
            if (!is_string($input[$field]) || $input[$field] === '' || strlen($input[$field]) > 80) {
                throw new DomainException('Identificador de simulación inválido.');
            }
        }
        if (!in_array($input['state'], ['pending', 'done'], true)) {
            throw new DomainException('Estado sintético no admitido.');
        }
        foreach (self::PROCESSES as $process) {
            foreach ($process['procedures'] as $procedure) {
                foreach ($procedure['tasks'] as $task) {
                    if ($process['id'] === $input['process_id']
                        && $procedure['id'] === $input['procedure_id']
                        && $task['id'] === $input['task_id']) {
                        return [
                            'process_id' => $process['id'],
                            'procedure_id' => $procedure['id'],
                            'task_id' => $task['id'],
                            'state' => $input['state'],
                            'persisted' => false,
                            'dispatched' => false,
                        ];
                    }
                }
            }
        }
        throw new DomainException('La tarea no pertenece al procedimiento y proceso indicados.');
    }
}
