<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments\Wompi;

use Closure;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Owner-only Wompi sandbox probe. Only two fixed paths on one HTTPS host are
 * reachable, with redirect-following disabled. No real card/customer input.
 *
 * @phpstan-type ProbeResult array{reference:string,transaction_id:string,status:string}
 */
class WompiSandboxHttpTransport
{
    private const CONTEXT = 'condor-platform-settings-v1';
    private const AMOUNT_CENTS = 150000;

    /** @param Closure(string,string,array<string,mixed>|null,string,string):array<string,mixed>|null $http */
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $master,
        private readonly ?Closure $http = null,
    ) {
    }

    public static function enabled(): bool
    {
        return getenv('CONDOR_PAYMENTS_SANDBOX_ENABLED') === 'true';
    }

    /** @return ProbeResult */
    public function testPayment(): array
    {
        if (!self::enabled()) throw new DomainException('WOMPI_SANDBOX_DISABLED');
        $public = $this->secret('wompi.public_key', 'WOMPI_PUBLIC_KEY_FILE', 'pub_test_');
        $private = $this->secret('wompi.private_key', 'WOMPI_PRIVATE_KEY_FILE', 'prv_test_');
        $integrity = $this->secret('wompi.integrity_secret', 'WOMPI_INTEGRITY_SECRET_FILE', 'test_integrity_');
        // Wompi deprecated GET /merchants/{public_key} on 2026-10-31.
        $merchant = $this->request('GET', '/merchants/info', null, $public, $private);
        $acceptance = $merchant['data']['presigned_acceptance']['acceptance_token'] ?? null;
        $personal = $merchant['data']['presigned_personal_data_auth']['acceptance_token'] ?? null;
        if (!is_string($acceptance) || strlen($acceptance) < 16
            || !is_string($personal) || strlen($personal) < 16) {
            throw new DomainException('WOMPI_SANDBOX_ACCEPTANCE_UNAVAILABLE');
        }

        $reference = 'condor-sandbox-'.bin2hex(random_bytes(12));
        $signature = hash('sha256', $reference.self::AMOUNT_CENTS.'COP'.$integrity);
        $transaction = $this->request('POST', '/transactions', [
            'reference' => $reference,
            'amount_in_cents' => self::AMOUNT_CENTS,
            'currency' => 'COP',
            'customer_email' => 'sandbox-test@example.com',
            // Wompi's published test-only NEQUI phone; no real payer/card data.
            'payment_method_type' => 'NEQUI',
            'payment_method' => ['type' => 'NEQUI', 'phone_number' => '3991111111'],
            'signature' => $signature,
            'acceptance_token' => $acceptance,
            'accept_personal_auth' => $personal,
        ], $public, $private);
        $tx = $transaction['data'] ?? null;
        if (!is_array($tx) || ($tx['reference'] ?? null) !== $reference
            || ($tx['amount_in_cents'] ?? null) !== self::AMOUNT_CENTS
            || ($tx['currency'] ?? null) !== 'COP'
            || !is_string($tx['id'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{6,120}\z/D', $tx['id']) !== 1
            || !in_array($tx['status'] ?? null, ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'], true)) {
            throw new DomainException('WOMPI_SANDBOX_RESPONSE_UNVERIFIED');
        }
        return ['reference' => $reference, 'transaction_id' => $tx['id'], 'status' => $tx['status']];
    }

    public function verifyEvent(string $payload, string $checksum): bool
    {
        if (!self::enabled()) return false;
        try {
            $secret = $this->secret('wompi.events_secret', 'WOMPI_EVENTS_SECRET_FILE', 'test_events_');
            return WompiPaymentGateway::verifySignedSandboxEvent($payload, $checksum, $secret);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Strictly allowlisted URLs. The HTTP Closure makes host and payload tests
     * deterministic without making a real network request.
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $payload, string $public, string $private): array
    {
        if (!self::enabled() || !(($method === 'GET' && $path === '/merchants/info')
            || ($method === 'POST' && $path === '/transactions'))) {
            throw new DomainException('WOMPI_SANDBOX_PATH_FORBIDDEN');
        }
        $url = WompiPaymentGateway::SANDBOX_URL.$path;
        try {
            $response = ($this->http ?? self::nativeHttp(...))($method, $url, $payload, $public, $private);
        } catch (Throwable) {
            throw new DomainException('WOMPI_SANDBOX_UNAVAILABLE');
        }
        return $response;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private static function nativeHttp(string $method, string $url, ?array $payload, string $public, string $private): array
    {
        if (!in_array($url, [
            WompiPaymentGateway::SANDBOX_URL.'/merchants/info',
            WompiPaymentGateway::SANDBOX_URL.'/transactions',
        ], true)) {
            throw new DomainException('WOMPI_SANDBOX_PATH_FORBIDDEN');
        }
        $headers = ['Accept: application/json', 'X-Merchant-Public-Key: '.$public];
        if ($method === 'POST') {
            $headers[] = 'Authorization: Bearer '.$private;
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 5,
                'follow_location' => 0,
                'ignore_errors' => true,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($body) || strlen($body) > 65536
            || preg_match('/\AHTTP\/\S+ (200|201)\b/', $statusLine) !== 1) {
            throw new DomainException('WOMPI_SANDBOX_UNAVAILABLE');
        }
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new DomainException('WOMPI_SANDBOX_RESPONSE_UNVERIFIED');
        return $data;
    }

    /** Read encrypted platform setting; only absent keys may fall back to secured files. */
    private function secret(string $setting, string $fileEnv, string $prefix): string
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT plain_value, encrypted_value, nonce, key_version, secret_suffix '
                .'FROM condor_platform_integration_setting WHERE setting_key = ?', [$setting],
            );
        } catch (Throwable) {
            // A broken encrypted store is not permission to use an unrelated key.
            throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
        }
        if ($row !== false) {
            if (($row['plain_value'] ?? null) !== null
                || (int) ($row['key_version'] ?? 0) !== 1
                || !is_string($row['encrypted_value'] ?? null)
                || !is_string($row['nonce'] ?? null)
                || !is_string($row['secret_suffix'] ?? null)
                || strlen($this->master) < 32
                || !function_exists('sodium_crypto_secretbox_open')) {
                throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
            }
            $cipher = base64_decode($row['encrypted_value'], true);
            $nonce = base64_decode($row['nonce'], true);
            if (!is_string($cipher) || !is_string($nonce)
                || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
            }
            $key = hash_hkdf('sha256', $this->master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::CONTEXT);
            try {
                $value = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            } finally {
                sodium_memzero($key);
            }
            if (!is_string($value)
                || !hash_equals($row['secret_suffix'], substr($value, -4))) {
                throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
            }
        } else {
            $file = getenv($fileEnv);
            if (!is_string($file) || $file === '' || !is_file($file) || is_link($file)
                || !is_readable($file) || filesize($file) > 1024) {
                throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
            }
            $perms = fileperms($file);
            if ($perms === false || ($perms & 0x003f) !== 0) {
                throw new DomainException('WOMPI_SANDBOX_KEYS_UNAVAILABLE');
            }
            $value = trim((string) file_get_contents($file));
        }
        if (preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_-]{16,}\z/D', $value) !== 1) {
            throw new DomainException('WOMPI_TEST_SECRET_ONLY');
        }
        return $value;
    }
}
