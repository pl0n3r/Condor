<?php

declare(strict_types=1);

/*
 * Backup lógico de MariaDB/MySQL usando solo PDO con la cuenta de aplicación.
 *
 * Las consultas de metadata permanecen bufferizadas. Solo el SELECT de filas
 * se vuelve no bufferizado durante el streaming y siempre cierra su cursor
 * antes de restaurar el modo normal. Los fallos salen con códigos por etapa
 * allowlisted para diagnosticar producción sin exponer SQL ni credenciales.
 *
 * Uso: DATABASE_URL=... php backup-database-pdo.php <salida.sql>
 */

const EXIT_CONNECT = 31;
const EXIT_FILESYSTEM = 32;
const EXIT_SNAPSHOT = 33;
const EXIT_TABLE_LIST = 34;
const EXIT_METADATA = 35;
const EXIT_ROWS = 36;
const EXIT_COMMIT = 37;
const EXIT_CHECKSUM = 38;

function failStage(string $stage, int $code, string $message): never
{
    fwrite(STDERR, "backup-database-pdo.php: stage={$stage}; {$message}\n");
    exit($code);
}

function stageCode(string $stage): int
{
    return match ($stage) {
        'snapshot' => EXIT_SNAPSHOT,
        'table-list' => EXIT_TABLE_LIST,
        'metadata' => EXIT_METADATA,
        'rows' => EXIT_ROWS,
        'commit' => EXIT_COMMIT,
        default => EXIT_METADATA,
    };
}

$output = $argv[1] ?? '';
if ($output === '') {
    failStage('filesystem', EXIT_FILESYSTEM, 'falta la ruta de salida.');
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    failStage('connect', EXIT_CONNECT, 'falta DATABASE_URL en el entorno.');
}

$parts = parse_url(preg_replace('#^mariadb://#i', 'mysql://', $url) ?? '');
if (!is_array($parts) || !isset($parts['host'], $parts['path'])
    || !in_array(strtolower($parts['scheme'] ?? ''), ['mysql'], true)) {
    failStage('connect', EXIT_CONNECT, 'DATABASE_URL no es una URL mysql:// o mariadb:// válida.');
}

$database = rawurldecode(ltrim($parts['path'], '/'));
$host = trim($parts['host'], '[]');
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $host,
    (int) ($parts['port'] ?? 3306),
    $database,
);

try {
    $pdo = new PDO(
        $dsn,
        rawurldecode($parts['user'] ?? ''),
        rawurldecode($parts['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
    );
} catch (Throwable) {
    failStage('connect', EXIT_CONNECT, 'no fue posible conectar a la base de datos.');
}
unset($url, $parts);

$handle = fopen($output, 'wb');
if ($handle === false) {
    failStage('filesystem', EXIT_FILESYSTEM, 'no fue posible crear el archivo de salida.');
}

$hash = hash_init('sha256');
$write = static function (string $sql) use ($handle, $hash): void {
    $offset = 0;
    $length = strlen($sql);
    while ($offset < $length) {
        $remaining = substr($sql, $offset);
        $written = fwrite($handle, $remaining);
        if ($written === false || $written === 0) {
            failStage('filesystem', EXIT_FILESYSTEM, 'no fue posible escribir el backup completo.');
        }
        hash_update($hash, substr($remaining, 0, $written));
        $offset += $written;
    }
};

$stage = 'snapshot';
try {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $stage = 'table-list';
    $tableStatement = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tables = $tableStatement->fetchAll(PDO::FETCH_COLUMN, 0);
    $tableStatement->closeCursor();
    sort($tables, SORT_STRING);

    $write("-- Condor backup PDO\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $quoted = chr(96) . str_replace(chr(96), chr(96).chr(96), (string) $table) . chr(96);

        $stage = 'metadata';
        $createStatement = $pdo->query("SHOW CREATE TABLE {$quoted}");
        $create = $createStatement->fetch(PDO::FETCH_NUM);
        $createStatement->closeCursor();
        if (!is_array($create) || !is_string($create[1] ?? null) || $create[1] === '') {
            failStage('metadata', EXIT_METADATA, 'no fue posible leer la definición de una tabla.');
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
                failStage('metadata', EXIT_METADATA, 'no fue posible identificar una columna.');
            }
            if (str_contains($extra, 'GENERATED')) {
                continue;
            }
            $insertableColumns[] = chr(96) . str_replace(chr(96), chr(96).chr(96), $name) . chr(96);
        }
        if ($insertableColumns === [] && $expected > 0) {
            failStage('metadata', EXIT_METADATA, 'una tabla con filas no tiene columnas insertables.');
        }
        $columnList = implode(',', $insertableColumns);

        $write("DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n");

        $dumped = 0;
        $batch = [];
        if ($expected > 0) {
            $stage = 'rows';
            $rows = null;
            $pdo->setAttribute(Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
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
                            failStage('rows', EXIT_ROWS, 'no fue posible serializar un valor.');
                        }
                        $values[] = $quotedValue;
                    }
                    $batch[] = '(' . implode(',', $values) . ')';
                    $dumped++;
                    if (count($batch) === 200) {
                        $write(
                            "INSERT INTO {$quoted} ({$columnList}) VALUES "
                            . implode(",\n", $batch)
                            . ";\n",
                        );
                        $batch = [];
                    }
                }
            } finally {
                if ($rows instanceof PDOStatement) {
                    $rows->closeCursor();
                }
                $pdo->setAttribute(Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
            }

            if ($batch !== []) {
                $write(
                    "INSERT INTO {$quoted} ({$columnList}) VALUES "
                    . implode(",\n", $batch)
                    . ";\n",
                );
            }
        }
        $write("\n");

        if ($dumped !== $expected) {
            failStage('rows', EXIT_ROWS, 'el conteo de filas del backup no coincide con el snapshot.');
        }
    }

    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    $stage = 'commit';
    $pdo->exec('COMMIT');
} catch (Throwable) {
    failStage($stage, stageCode($stage), 'falló la etapa de backup; detalles internos redactados.');
}

$expectedChecksum = hash_final($hash);
if (!fclose($handle)) {
    failStage('filesystem', EXIT_FILESYSTEM, 'no fue posible cerrar el backup.');
}

$actualChecksum = hash_file('sha256', $output);
if (!is_string($actualChecksum) || !hash_equals($expectedChecksum, $actualChecksum)) {
    failStage('checksum', EXIT_CHECKSUM, 'el checksum SHA-256 no coincide con los bytes escritos.');
}

fwrite(
    STDERR,
    sprintf(
        "backup-database-pdo.php: %d tablas volcadas y verificadas; checksum SHA-256 verificado: %s.\n",
        count($tables),
        $actualChecksum,
    ),
);
