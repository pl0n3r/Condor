<?php

declare(strict_types=1);

/*
 * Backup lógico de MariaDB/MySQL usando solo PDO con la cuenta de aplicación.
 *
 * Existe porque el usuario de base de datos de Hostinger no tiene los
 * privilegios que exigen mariadb-dump/mysqldump (Access denied, GTID,
 * PROCESS). Este camino solo necesita SELECT y SHOW CREATE TABLE sobre las
 * tablas propias. Escribe SQL plano restaurable por verify-backup-restore.sh
 * y comprueba al final que cada tabla volcó exactamente las filas contadas
 * dentro de la misma transacción consistente (fail-closed).
 *
 * Uso: DATABASE_URL=... php backup-database-pdo.php <salida.sql>
 */

function fail(string $message): never
{
    fwrite(STDERR, "backup-database-pdo.php: {$message}\n");
    exit(1);
}

$output = $argv[1] ?? '';
if ($output === '') {
    fail('falta la ruta de salida.');
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fail('falta DATABASE_URL en el entorno.');
}

$parts = parse_url(preg_replace('#^mariadb://#i', 'mysql://', $url) ?? '');
if (!is_array($parts) || !isset($parts['host'], $parts['path'])
    || !in_array(strtolower($parts['scheme'] ?? ''), ['mysql'], true)) {
    fail('DATABASE_URL no es una URL mysql:// o mariadb:// válida.');
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
            Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false,
        ],
    );
} catch (PDOException) {
    // El mensaje del driver puede incluir host o usuario; no se reenvía.
    fail('no fue posible conectar a la base de datos.');
}
unset($url, $parts);

$handle = fopen($output, 'wb');
if ($handle === false) {
    fail('no fue posible crear el archivo de salida.');
}

$write = static function (string $sql) use ($handle): void {
    if (fwrite($handle, $sql) === false) {
        fail('no fue posible escribir el backup.');
    }
};

try {
    // Snapshot consistente sin LOCK TABLES ni privilegios globales.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_COLUMN, 0);
    sort($tables, SORT_STRING);

    $write("-- Condor backup PDO\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
        $expected = (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();

        $write("DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n");

        $dumped = 0;
        $batch = [];
        $rows = $pdo->query("SELECT * FROM {$quoted}", PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $values = array_map(
                static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                $row,
            );
            $batch[] = '(' . implode(',', $values) . ')';
            $dumped++;
            if (count($batch) === 200) {
                $write("INSERT INTO {$quoted} VALUES " . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        $rows->closeCursor();
        if ($batch !== []) {
            $write("INSERT INTO {$quoted} VALUES " . implode(",\n", $batch) . ";\n");
        }
        $write("\n");

        if ($dumped !== $expected) {
            fail("la tabla {$table} volcó {$dumped} filas y se esperaban {$expected}.");
        }
    }

    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    $pdo->exec('COMMIT');
} catch (PDOException) {
    fail('la consulta de backup falló; SQL y parámetros redactados.');
}

if (!fclose($handle)) {
    fail('no fue posible cerrar el backup.');
}

fwrite(STDERR, sprintf("backup-database-pdo.php: %d tablas volcadas y verificadas.\n", count($tables)));
