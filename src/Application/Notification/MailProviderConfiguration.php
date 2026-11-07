<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Internal-only resolution of platform-owner mail settings. Never returns the API
 * key separately or reads the public/redacted platform settings API.
 */
final readonly class MailProviderConfiguration
{
    private const MASTER_CONTEXT = 'condor-platform-settings-v1';
    private const PROVIDER_KEY = 'mail.provider';
    private const FROM_KEY = 'mail.from';
    private const API_KEY = 'mail.api_key';

    public function __construct(
        private Connection $connection,
        #[Autowire('%env(APP_SECRET)%')]
        private string $appSecret,
    ) {
    }

    /** @return array{dsn: string, from: string, provider: string}|null */
    public function resolve(): ?array
    {
        try {
            $storedProvider = $this->storedValue(self::PROVIDER_KEY, false);
            $storedFrom = $this->storedValue(self::FROM_KEY, false);
            $provider = $storedProvider ?? self::environment('CONDOR_MAIL_PROVIDER');
            $from = $storedFrom ?? self::environment('CONDOR_MAIL_FROM');
            $storedKey = $provider === 'resend' ? $this->storedValue(self::API_KEY, true) : null;
        } catch (DomainException) {
            // A corrupt/tampered stored setting is not permission to use a fallback.
            return null;
        } catch (Throwable) {
            // Missing schema/DB during a partial deployment: retain the legacy
            // environment-only path without logging the underlying exception.
            return self::fromEnvironment();
        }

        $dsn = self::environment('CONDOR_MAILER_DSN');
        if ($storedKey !== null) {
            $dsn = sprintf('smtps://%s:%s@%s:%d', 'resend', rawurlencode($storedKey), 'smtp.resend.com', 465);
        } elseif ($storedProvider !== null
            && $storedProvider !== self::environment('CONDOR_MAIL_PROVIDER')) {
            // Do not pair a stored provider with an unrelated environment DSN.
            return null;
        }

        return self::validated($dsn, $from, $provider);
    }

    /**
     * Read one allowlisted record with a purpose-limited SQL projection. A
     * redacted repository getter must never be used to obtain a secret.
     */
    private function storedValue(string $name, bool $secret): ?string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT plain_value, encrypted_value, nonce, key_version, secret_suffix '
            .'FROM condor_platform_integration_setting WHERE setting_key = ?',
            [$name],
        );
        if ($row === false) {
            return null;
        }

        if ($secret) {
            if (($row['plain_value'] ?? null) !== null
                || (int) ($row['key_version'] ?? 0) !== 1
                || !is_string($row['encrypted_value'] ?? null)
                || !is_string($row['nonce'] ?? null)
                || !is_string($row['secret_suffix'] ?? null)
                || !$this->safeMasterKey()
                || !function_exists('sodium_crypto_secretbox_open')) {
                throw new DomainException('Configuración de correo no disponible.');
            }
            $ciphertext = base64_decode($row['encrypted_value'], true);
            $nonce = base64_decode($row['nonce'], true);
            if (!is_string($ciphertext) || !is_string($nonce)
                || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES
                || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new DomainException('Configuración de correo no disponible.');
            }
            $key = hash_hkdf('sha256', $this->appSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::MASTER_CONTEXT);
            try {
                $value = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            } finally {
                sodium_memzero($key);
            }
            if (!is_string($value)) {
                throw new DomainException('Configuración de correo no disponible.');
            }
            $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
            if ($characters === false || count($characters) < 4
                || !hash_equals($row['secret_suffix'], implode('', array_slice($characters, -4)))) {
                throw new DomainException('Configuración de correo no disponible.');
            }
        } else {
            if (($row['encrypted_value'] ?? null) !== null || !is_string($row['plain_value'] ?? null)
                || ($row['nonce'] ?? null) !== null || ($row['key_version'] ?? null) !== null
                || ($row['secret_suffix'] ?? null) !== null) {
                throw new DomainException('Configuración de correo no disponible.');
            }
            $value = $row['plain_value'];
        }

        if ($value === '' || strlen($value) > 8192 || str_contains($value, "\0")) {
            throw new DomainException('Configuración de correo no disponible.');
        }
        return $secret ? $value : trim($value);
    }

    private function safeMasterKey(): bool
    {
        return strlen(trim($this->appSecret)) >= 32
            && preg_match('/change.?me|example|placeholder|replace.?me|sample|your.?secret/i', $this->appSecret) !== 1;
    }

    /** @return array{dsn: string, from: string, provider: string}|null */
    private static function fromEnvironment(): ?array
    {
        return self::validated(
            self::environment('CONDOR_MAILER_DSN'),
            self::environment('CONDOR_MAIL_FROM'),
            self::environment('CONDOR_MAIL_PROVIDER'),
        );
    }

    /** @return array{dsn: string, from: string, provider: string}|null */
    private static function validated(string $dsn, string $from, string $provider): ?array
    {
        if ($dsn === '' || $from === '' || $provider === ''
            || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $provider) !== 1
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $scheme = parse_url($dsn, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['smtp', 'smtps'], true)) {
            return null;
        }
        return ['dsn' => $dsn, 'from' => $from, 'provider' => $provider];
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}
