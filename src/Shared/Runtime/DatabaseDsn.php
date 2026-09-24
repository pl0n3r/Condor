<?php

declare(strict_types=1);

namespace App\Shared\Runtime;

use InvalidArgumentException;

final class DatabaseDsn
{
    /**
     * Interpreta DATABASE_URL con la misma semántica relevante de DBAL 4.4:
     * decode de componentes, aliases de esquema y parámetros de query.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $url): array
    {
        if ($url === '') {
            throw new InvalidArgumentException('DATABASE_URL está vacía.');
        }

        $schemeEnd = strpos($url, '://');
        if ($schemeEnd === false) {
            throw new InvalidArgumentException(
                'DATABASE_URL no incluye esquema.',
            );
        }

        $rest = substr($url, $schemeEnd + 3);
        $authorityLength = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $authorityLength);
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (str_starts_with($hostPort, '[')) {
            $close = strpos($hostPort, ']');
            if ($close === false) {
                throw new InvalidArgumentException('host IPv6 inválido.');
            }
            $suffix = substr($hostPort, $close + 1);
            if ($suffix !== '' && preg_match('/^:[0-9]+$/', $suffix) !== 1) {
                throw new InvalidArgumentException('host/puerto inválido.');
            }
        } elseif (substr_count($hostPort, ':') > 1) {
            throw new InvalidArgumentException(
                'host IPv6 debe usar corchetes.',
            );
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException(
                'DATABASE_URL no es una DSN MySQL/MariaDB válida.',
            );
        }

        foreach ($parts as $key => $value) {
            if (is_string($value)) {
                $parts[$key] = rawurldecode($value);
            }
        }

        $params = [];
        if (isset($parts['scheme'])) {
            $scheme = str_replace('-', '_', strtolower($parts['scheme']));
            $params['driver'] = match ($scheme) {
                'mysql', 'mariadb' => 'pdo_mysql',
                default => $scheme,
            };
        }
        foreach (['host', 'port', 'user'] as $key) {
            if (isset($parts[$key])) {
                $params[$key] = $parts[$key];
            }
        }
        if (isset($parts['pass'])) {
            $params['password'] = $parts['pass'];
        }
        if (isset($parts['path'])) {
            $path = $parts['path'];
            if (isset($params['host']) && str_starts_with($path, '/')) {
                $path = substr($path, 1);
            }
            $params['dbname'] = $path;
        }
        if (isset($parts['query'])) {
            $query = [];
            parse_str($parts['query'], $query);
            $params = array_merge($params, $query);
        }
        if (is_string($params['host'] ?? null)) {
            $params['host'] = trim($params['host'], '[]');
        }

        return self::validate($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function validate(array $params): array
    {
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
