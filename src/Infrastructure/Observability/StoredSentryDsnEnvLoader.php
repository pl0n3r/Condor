<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Throwable;

/**
 * Narrow, fail-closed bridge for the platform-owner Sentry DSN.
 *
 * Symfony's native string processor must remain untouched. The dedicated
 * condor_sentry_dsn processor resolves an explicit SENTRY_DSN first, then
 * the encrypted owner setting, and finally the empty disabled value.
 * Do not register this service as a global env loader: Symfony can invoke
 * loaders before Doctrine is usable and cache a false-negative DSN.
 *
 * No other environment variable is modified, queried or returned by the loader.
 * Never include the DSN, ciphertext, nonce, APP_SECRET or exception in logs.
 */
final class StoredSentryDsnEnvLoader implements EnvVarLoaderInterface, EnvVarProcessorInterface
{
    private const SETTING_KEY = 'sentry.dsn';
    private const CRYPTO_CONTEXT = 'condor-platform-settings-v1';
    private const CACHE_TTL_SECONDS = 30;

    private bool $loaded = false;
    private int $cacheExpiresAt = 0;
    private bool $loading = false;
    private ?string $cachedDsn = null;

    /** @param Closure():Connection $connectionFactory */
    public function __construct(
        private readonly ContainerInterface $container,
        #[AutowireServiceClosure('doctrine.dbal.default_connection')]
        private readonly Closure $connectionFactory,
    ) {
    }

    /** @return array{condor_sentry_dsn:string} */
    public static function getProvidedTypes(): array
    {
        return ['condor_sentry_dsn' => 'string'];
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): mixed
    {
        if ('condor_sentry_dsn' === $prefix && 'SENTRY_DSN' === $name) {
            // A deliberately defined process value (including '') is authoritative:
            // an explicit empty value can disable capture without querying storage.
            $explicit = $_ENV['SENTRY_DSN'] ?? $_SERVER['SENTRY_DSN'] ?? getenv('SENTRY_DSN');
            if (is_string($explicit)) {
                return $explicit;
            }

            try {
                $master = $getEnv('APP_SECRET');
                if (is_string($master) && null !== ($dsn = $this->readStoredDsn($master))) {
                    return $dsn;
                }
            } catch (Throwable) {
                // No credentials, DB outage and invalid cipher never break startup.
            }

            return '';
        }

        // All unrelated keys and native prefixes keep Symfony's own semantics.
        return (new EnvVarProcessor($this->container))->getEnv($prefix, $name, $getEnv);
    }

    /** @return array<string, string> */
    public function loadEnvVars(): array
    {
        // The loader is a fallback for unset/empty variables; non-Sentry names
        // are never returned. The custom processor preserves explicit env values.
        $master = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!is_string($master)) {
            return [];
        }

        $dsn = $this->readStoredDsn($master);

        return null === $dsn ? [] : ['SENTRY_DSN' => $dsn];
    }

    private function readStoredDsn(string $master): ?string
    {
        if ($this->loading) {
            return null;
        }
        if ($this->loaded && time() < $this->cacheExpiresAt) {
            return $this->cachedDsn;
        }
        // Expired entries are revalidated so owner changes become visible
        // without restarting a long-lived worker.
        $this->cachedDsn = null;
        $keyCandidate = trim($master);
        if (strlen($keyCandidate) < 32
            || preg_match('/change.?me|example|placeholder|replace.?me|sample|your.?secret/i', $keyCandidate) === 1
            || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        $this->loading = true;
        $startupConnection = null;
        try {
            // Resolving the SDK's DSN can happen before the dependency-injection
            // graph is ready for Doctrine. A short-lived DBAL connection avoids
            // the boot-time cycle, using only the existing DATABASE_URL.
            $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
            $isProdKernel = $this->container->hasParameter('kernel.environment')
                && $this->container->getParameter('kernel.environment') === 'prod';
            if ($isProdKernel && is_string($databaseUrl) && $databaseUrl !== '') {
                $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($databaseUrl);
                if (($params['driver'] ?? null) !== 'pdo_mysql') {
                    return null;
                }
                $startupConnection = DriverManager::getConnection($params);
                $connection = $startupConnection;
            } else {
                $connection = ($this->connectionFactory)();
            }
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
            $startupConnection?->close();
            $this->loaded = true;
            $this->cacheExpiresAt = time() + self::CACHE_TTL_SECONDS;
            $this->loading = false;
        }
    }
}
