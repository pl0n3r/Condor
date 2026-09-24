<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use PDO;
use PDOStatement;
use Throwable;

final class PdoDatabaseBackup
{
    public const EXIT_CONNECT = 31;
    public const EXIT_FILESYSTEM = 32;
    public const EXIT_SNAPSHOT = 33;
    public const EXIT_TABLE_LIST = 34;
    public const EXIT_METADATA = 35;
    public const EXIT_ROWS = 36;
    public const EXIT_COMMIT = 37;
    public const EXIT_CHECKSUM = 38;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{tables:int, checksum:string} */
    public function write(string $output): array
    {
        if ($output === '') {
            throw new BackupFailure(
                'filesystem',
                self::EXIT_FILESYSTEM,
                'falta la ruta de salida.',
            );
        }

        try {
            $nativeConnection = $this->connection->getNativeConnection();
            if (!$nativeConnection instanceof PDO) {
                throw new BackupFailure(
                    'connect',
                    self::EXIT_CONNECT,
                    'el driver de base de datos no expone una conexión PDO.',
                );
            }
            $pdo = $nativeConnection;
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new BackupFailure(
                'connect',
                self::EXIT_CONNECT,
                'no fue posible obtener la conexión Doctrine activa.',
            );
        }

        $handle = @fopen($output, 'wb');
        if ($handle === false) {
            throw new BackupFailure(
                'filesystem',
                self::EXIT_FILESYSTEM,
                'no fue posible crear el archivo de salida.',
            );
        }

        $hash = hash_init('sha256');
        $stage = 'snapshot';
        $tables = [];

        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $stage = 'table-list';
            $tableStatement = $pdo->query(
                "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
            );
            $tables = $tableStatement->fetchAll(PDO::FETCH_COLUMN, 0);
            $tableStatement->closeCursor();
            sort($tables, SORT_STRING);

            self::writeAll(
                $handle,
                $hash,
                "-- Condor backup PDO\n"
                ."SET NAMES utf8mb4;\n"
                ."SET FOREIGN_KEY_CHECKS=0;\n\n",
            );

            foreach ($tables as $table) {
                $quoted = chr(96)
                    .str_replace(chr(96), chr(96).chr(96), (string) $table)
                    .chr(96);

                $stage = 'metadata';
                $createStatement = $pdo->query("SHOW CREATE TABLE {$quoted}");
                $create = $createStatement->fetch(PDO::FETCH_NUM);
                $createStatement->closeCursor();
                if (
                    !is_array($create)
                    || !is_string($create[1] ?? null)
                    || $create[1] === ''
                ) {
                    throw new BackupFailure(
                        'metadata',
                        self::EXIT_METADATA,
                        'no fue posible leer la definición de una tabla.',
                    );
                }

                $countStatement = $pdo->query("SELECT COUNT(*) FROM {$quoted}");
                $expected = (int) $countStatement->fetchColumn();
                $countStatement->closeCursor();

                $columnStatement = $pdo->query("SHOW FULL COLUMNS FROM {$quoted}");
                $columns = $columnStatement->fetchAll(PDO::FETCH_ASSOC);
                $columnStatement->closeCursor();

                $insertableColumns = [];
                foreach ($columns as $column) {
                    $name = $column['Field'] ?? null;
                    $extra = strtoupper((string) ($column['Extra'] ?? ''));
                    if (!is_string($name) || $name === '') {
                        throw new BackupFailure(
                            'metadata',
                            self::EXIT_METADATA,
                            'no fue posible identificar una columna.',
                        );
                    }
                    if (str_contains($extra, 'GENERATED')) {
                        continue;
                    }
                    $insertableColumns[] = chr(96)
                        .str_replace(chr(96), chr(96).chr(96), $name)
                        .chr(96);
                }
                if ($insertableColumns === [] && $expected > 0) {
                    throw new BackupFailure(
                        'metadata',
                        self::EXIT_METADATA,
                        'una tabla con filas no tiene columnas insertables.',
                    );
                }
                $columnList = implode(',', $insertableColumns);

                self::writeAll(
                    $handle,
                    $hash,
                    "DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n",
                );

                $dumped = 0;
                $batch = [];
                if ($expected > 0) {
                    $stage = 'rows';
                    $rows = null;
                    $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
                    try {
                        $rows = $pdo->query(
                            "SELECT {$columnList} FROM {$quoted}",
                            PDO::FETCH_NUM,
                        );
                        foreach ($rows as $row) {
                            $values = [];
                            foreach ($row as $value) {
                                if ($value === null) {
                                    $values[] = 'NULL';
                                    continue;
                                }
                                $quotedValue = $pdo->quote((string) $value);
                                if ($quotedValue === false) {
                                    throw new BackupFailure(
                                        'rows',
                                        self::EXIT_ROWS,
                                        'no fue posible serializar un valor.',
                                    );
                                }
                                $values[] = $quotedValue;
                            }
                            $batch[] = '('.implode(',', $values).')';
                            ++$dumped;
                            if (count($batch) === 200) {
                                self::writeAll(
                                    $handle,
                                    $hash,
                                    "INSERT INTO {$quoted} ({$columnList}) VALUES "
                                    .implode(",\n", $batch)
                                    .";\n",
                                );
                                $batch = [];
                            }
                        }
                    } finally {
                        if ($rows instanceof PDOStatement) {
                            $rows->closeCursor();
                        }
                        $pdo->setAttribute(
                            \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY,
                            true,
                        );
                    }

                    if ($batch !== []) {
                        self::writeAll(
                            $handle,
                            $hash,
                            "INSERT INTO {$quoted} ({$columnList}) VALUES "
                            .implode(",\n", $batch)
                            .";\n",
                        );
                    }
                }
                self::writeAll($handle, $hash, "\n");

                if ($dumped !== $expected) {
                    throw new BackupFailure(
                        'rows',
                        self::EXIT_ROWS,
                        'el conteo de filas del backup no coincide con el snapshot.',
                    );
                }
            }

            self::writeAll($handle, $hash, "SET FOREIGN_KEY_CHECKS=1;\n");
            $stage = 'commit';
            $pdo->exec('COMMIT');
        } catch (BackupFailure $failure) {
            self::cleanupFailedOutput($pdo, $handle, $output);
            throw $failure;
        } catch (Throwable) {
            self::cleanupFailedOutput($pdo, $handle, $output);
            throw new BackupFailure(
                $stage,
                self::stageCode($stage),
                'falló la etapa de backup; detalles internos redactados.',
            );
        }

        $expectedChecksum = hash_final($hash);
        if (!fclose($handle)) {
            @unlink($output);
            throw new BackupFailure(
                'filesystem',
                self::EXIT_FILESYSTEM,
                'no fue posible cerrar el backup.',
            );
        }

        $actualChecksum = hash_file('sha256', $output);
        if (
            !is_string($actualChecksum)
            || !hash_equals($expectedChecksum, $actualChecksum)
        ) {
            @unlink($output);
            throw new BackupFailure(
                'checksum',
                self::EXIT_CHECKSUM,
                'el checksum SHA-256 no coincide con los bytes escritos.',
            );
        }

        $this->connection->close();

        return [
            'tables' => count($tables),
            'checksum' => $actualChecksum,
        ];
    }

    /** @param resource $handle */
    private static function writeAll($handle, \HashContext $hash, string $sql): void
    {
        $offset = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            $remaining = substr($sql, $offset);
            $written = fwrite($handle, $remaining);
            if ($written === false || $written === 0) {
                throw new BackupFailure(
                    'filesystem',
                    self::EXIT_FILESYSTEM,
                    'no fue posible escribir el backup completo.',
                );
            }
            hash_update($hash, substr($remaining, 0, $written));
            $offset += $written;
        }
    }

    private static function stageCode(string $stage): int
    {
        return match ($stage) {
            'snapshot' => self::EXIT_SNAPSHOT,
            'table-list' => self::EXIT_TABLE_LIST,
            'metadata' => self::EXIT_METADATA,
            'rows' => self::EXIT_ROWS,
            'commit' => self::EXIT_COMMIT,
            default => self::EXIT_METADATA,
        };
    }

    /** @param resource $handle */
    private static function cleanupFailedOutput(
        PDO $pdo,
        $handle,
        string $output,
    ): void {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        @fclose($handle);
        @unlink($output);
    }
}
