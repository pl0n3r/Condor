<?php

declare(strict_types=1);

namespace App\Shared\Runtime;

use Doctrine\DBAL\Tools\DsnParser;
use InvalidArgumentException;
use Throwable;

final class DatabaseDsn
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $url): array
    {
        if ($url === '') {
            throw new InvalidArgumentException('DATABASE_URL está vacía.');
        }

        try {
            $params = (new DsnParser([
                'mysql' => 'pdo_mysql',
                'mariadb' => 'pdo_mysql',
            ]))->parse($url);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'DATABASE_URL no es una DSN MySQL/MariaDB válida.',
            );
        }

        if (($params['driver'] ?? null) !== 'pdo_mysql') {
            throw new InvalidArgumentException(
                'DATABASE_URL debe usar MySQL/MariaDB mediante PDO.',
            );
        }

        $host = $params['host'] ?? null;
        $socket = $params['unix_socket'] ?? null;
        $database = $params['dbname'] ?? null;
        $user = $params['user'] ?? '';
        $password = $params['password'] ?? '';
        $port = $params['port'] ?? null;

        if (
            (!is_string($host) || $host === '')
            && (!is_string($socket) || $socket === '')
        ) {
            throw new InvalidArgumentException(
                'DATABASE_URL no incluye host ni socket Unix.',
            );
        }
        if (
            !is_string($database)
            || $database === ''
            || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1
        ) {
            throw new InvalidArgumentException(
                'DATABASE_URL no incluye un nombre de base válido.',
            );
        }
        if (!is_string($user) || !is_string($password)) {
            throw new InvalidArgumentException(
                'DATABASE_URL contiene credenciales inválidas.',
            );
        }
        if ($port !== null) {
            if (is_string($port) && ctype_digit($port)) {
                $port = (int) $port;
            }
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException(
                    'DATABASE_URL contiene un puerto inválido.',
                );
            }
            $params['port'] = $port;
        }

        $values = [$user, $password, $database];
        if (is_string($host)) {
            $values[] = $host;
        }
        if (is_string($socket)) {
            $values[] = $socket;
        }
        foreach ($values as $value) {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException(
                    'DATABASE_URL contiene caracteres de control no permitidos.',
                );
            }
        }

        $params['dbname'] = $database;
        $params['user'] = $user;
        $params['password'] = $password;

        return $params;
    }
}
