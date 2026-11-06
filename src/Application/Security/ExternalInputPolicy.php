<?php

declare(strict_types=1);

namespace App\Application\Security;

use DomainException;

/**
 * D-040-S17/S18: pure validation for synthetic inputs, never a network client.
 * Callers must pin the returned IP and turn off automatic HTTP redirects.
 */
final class ExternalInputPolicy
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const MAX_REDIRECTS = 3;

    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * @return array{mime:string,bytes:int,stored_name:string}
     */
    public static function inspectUpload(string $bytes, string $clientName, string $claimedMime): array
    {
        $length = strlen($bytes);
        if ($length === 0 || $length > self::MAX_UPLOAD_BYTES) {
            throw new DomainException('Upload vacío o fuera del límite.');
        }
        if ($clientName === '' || strlen($clientName) > 160
            || $clientName === '.' || $clientName === '..'
            || str_contains($clientName, '/') || str_contains($clientName, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $clientName) === 1) {
            throw new DomainException('Nombre de origen inseguro.');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($detected) || !isset(self::ALLOWED_MIME[$detected])
            || $claimedMime !== $detected) {
            throw new DomainException('Contenido/MIME no permitido o inconsistente.');
        }
        if (str_starts_with($detected, 'image/')) {
            $size = @getimagesizefromstring($bytes);
            if ($size === false || $size[0] <= 0 || $size[1] <= 0
                || $size[0] * $size[1] > 25_000_000) {
                throw new DomainException('Imagen inválida o demasiado grande.');
            }
        }

        return [
            'mime' => $detected,
            'bytes' => $length,
            'stored_name' => bin2hex(random_bytes(16)).'.'.self::ALLOWED_MIME[$detected],
        ];
    }

    /**
     * Inspects a destination WITHOUT DNS/network I/O. The caller supplies a
     * trusted resolver and MUST connect to one of the returned approved IPs.
     * @param callable(string):mixed $resolve
     * @return array{url:string,scheme:string,host:string,port:int,approved_ips:list<string>}
     */
    public static function inspectExternalUrl(string $url, callable $resolve): array
    {
        if ($url === '' || strlen($url) > 2048 || trim($url) !== $url
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new DomainException('URL remota inválida.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new DomainException('URL remota incompleta o con credenciales.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new DomainException('Esquema remoto no permitido.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            throw new DomainException('Puerto remoto no permitido.');
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || str_contains($host, '%')) {
            throw new DomainException('Host remoto inválido.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            self::assertPublicHostname($host);
            $addresses = $resolve($host);
        }
        if (!is_array($addresses) || $addresses === []) {
            throw new DomainException('Destino DNS sin direcciones verificables.');
        }
        $safeAddresses = [];
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::isPublicIp($address)) {
                throw new DomainException('Destino interno/reservado o resolución ambigua.');
            }
            $safeAddresses[] = $address;
        }

        return [
            'url' => $url,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'approved_ips' => array_values(array_unique($safeAddresses)),
        ];
    }

    /**
     * Each redirect is re-resolved. The HTTP adapter must disable automatic
     * redirects, pin each approved IP, and enforce time/response-size budgets.
     * @param list<string> $redirects
     * @param callable(string):mixed $resolve
     * @return list<array{url:string,scheme:string,host:string,port:int,approved_ips:list<string>}>
     */
    public static function inspectRedirectChain(string $initial, array $redirects, callable $resolve): array
    {
        if (count($redirects) > self::MAX_REDIRECTS) {
            throw new DomainException('Demasiadas redirecciones.');
        }
        $targets = [];
        foreach ([$initial, ...$redirects] as $url) {
            $target = self::inspectExternalUrl($url, $resolve);
            $previous = $targets[array_key_last($targets)] ?? null;
            if ($previous !== null && $previous['scheme'] === 'https' && $target['scheme'] !== 'https') {
                throw new DomainException('Redirección que degrada HTTPS.');
            }
            $targets[] = $target;
        }

        return $targets;
    }

    private static function assertPublicHostname(string $host): void
    {
        $labels = explode('.', $host);
        $suffix = (string) end($labels);
        if (strlen($host) > 253 || count($labels) < 2
            || in_array($suffix, ['local', 'localhost', 'internal', 'test', 'invalid'], true)
            || str_ends_with($host, '.home.arpa')
            || preg_match('/\A[a-z]{2,63}\z/D', $suffix) !== 1) {
            throw new DomainException('Host privado o mal formado.');
        }
        foreach ($labels as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1) {
                throw new DomainException('Etiqueta DNS inválida.');
            }
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($ip);
            return !str_contains($lower, '.') && !str_contains($lower, '::ffff:')
                && !str_starts_with($lower, '2002:') && !str_starts_with($lower, '2001:0:');
        }
        $value = ip2long($ip);
        if ($value === false) {
            return false;
        }
        foreach ([
            [0x64400000, 0xffc00000], // CGNAT 100.64.0.0/10
            [0xc0000000, 0xffffff00], // 192.0.0.0/24
            [0xc0000200, 0xffffff00], // TEST-NET-1
            [0xc6120000, 0xfffe0000], // benchmark 198.18.0.0/15
            [0xc6336400, 0xffffff00], // TEST-NET-2
            [0xcb007100, 0xffffff00], // TEST-NET-3
        ] as [$network, $mask]) {
            if (($value & $mask) === $network) {
                return false;
            }
        }

        return true;
    }
}
