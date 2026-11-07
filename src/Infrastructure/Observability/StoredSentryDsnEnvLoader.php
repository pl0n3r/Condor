<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Closure;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Throwable;

/**
 * Narrow, fail-closed bridge for the platform-owner Sentry DSN.
 *
 * Symfony 7.4 consults EnvVarLoaderInterface only for missing/empty environment
 * variables. Its string processor must also be decorated to make the encrypted
 * platform setting win when a real SENTRY_DSN environment value exists.
 *
 * No other environment variable is modified, queried or returned by the loader.
 * Never include the DSN, ciphertext, nonce, APP_SECRET or exception in logs.
 */
final class StoredSentryDsnEnvLoader implements EnvVarLoaderInterface, EnvVarProcessorInterface
{
    private const SETTING_KEY = 'sentry.dsn';
    private const CRYPTO_CONTEXT = 'condor-platform-settings-v1';

    private bool $loaded = false;
    private bool $loading = false;
    private ?string $cachedDsn = null;

    /** @param Closure():Connection $connectionFactory */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Closure $connectionFactory,
    ) {
    }

    /** @return array{string:string} */
    public static function getProvidedTypes(): array
    {
        return ['string' => 'string'];
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): mixed
    {
        if ('SENTRY_DSN' === $name && ('' === $prefix || 'string' === $prefix)) {
            try {
                $master = $getEnv('APP_SECRET');
                if (is_string($master) && null !== ($dsn = $this->readStoredDsn($master))) {
                    return $dsn;
                }
            } catch (Throwable) {
                // Missing APP_SECRET, database or encrypted settings cannot break boot.
            }
        }

        // Preserve Symfony's normal env/default precedence for every other key
        // and for the SENTRY_DSN fallback. Never mutate superglobals or putenv().
        return (new EnvVarProcessor($this->container))->getEnv($prefix, $name, $getEnv);
    }

    /** @return array<string, string> */
    public function loadEnvVars(): array
    {
        // The loader is a fallback for unset/empty variables; non-Sentry names
        // are never returned. The processor above covers a configured env DSN.
        $master = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!is_string($master)) {
            return [];
        }

        $dsn = $this->readStoredDsn($master);

        return null === $dsn ? [] : ['SENTRY_DSN' => $dsn];
    }

    private function readStoredDsn(string $master): ?string
    {
        if ($this->loaded || $this->loading) {
            return $this->cachedDsn;
        }
        $keyCandidate = trim($master);
        if (strlen($keyCandidate) < 32
            || preg_match('/change.?me|example|placeholder|replace.?me|sample|your.?secret/i', $keyCandidate) === 1
            || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        $this->loading = true;
        try {
            $connection = ($this->connectionFactory)();
            $row = $connection->fetchAssociative(
                'SELECT plain_value, encrypted_value, nonce, key_version, secret_suffix '
                .'FROM condor_platform_integration_setting WHERE setting_key = ?',
                [self::SETTING_KEY],
            );
            if (!is_array($row)
                || null !== ($row['plain_value'] ?? null)
                || (int) ($row['key_version'] ?? 0) !== 1
                || !is_string($row['encrypted_value'] ?? null)
                || !is_string($row['nonce'] ?? null)
                || !is_string($row['secret_suffix'] ?? null)) {
                return null;
            }
            $ciphertext = base64_decode($row['encrypted_value'], true);
            $nonce = base64_decode($row['nonce'], true);
            if (!is_string($ciphertext) || !is_string($nonce)
                || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return null;
            }

            $key = hash_hkdf('sha256', $master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::CRYPTO_CONTEXT);
            try {
                $dsn = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            } finally {
                sodium_memzero($key);
            }

            if (!is_string($dsn) || '' === trim($dsn)
                || strlen($dsn) > 8192 || str_contains($dsn, "\0")) {
                return null;
            }

            // The schema's suffix is four Unicode code points, not bytes.
            $characters = preg_split('//u', $dsn, -1, PREG_SPLIT_NO_EMPTY);
            if ($characters === false || count($characters) < 4
                || !hash_equals($row['secret_suffix'], implode('', array_slice($characters, -4)))) {
                return null;
            }

            return $this->cachedDsn = $dsn;
        } catch (Throwable) {
            // A database outage, a missing table or an invalid cipher is a
            // normal fail-closed fallback. No exception data is ever logged.
            return null;
        } finally {
            $this->loaded = true;
            $this->loading = false;
        }
    }
}
