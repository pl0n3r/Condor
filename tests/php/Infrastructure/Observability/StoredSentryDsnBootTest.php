<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Persistence\PlatformIntegrationSettingRepository;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sentry\SentrySdk;

/**
 * Real production-kernel wiring on a throwaway MariaDB fixture.
 * These tests inspect the SDK client, never capture or send an event.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoredSentryDsnBootTest extends TestCase
{
    private ?Connection $connection = null;
    private ?PlatformIntegrationSettingRepository $settings = null;
    private string $master = '';
    private bool $seeded = false;

    protected function setUp(): void
    {
        $url = getenv('DATABASE_URL');
        $secret = getenv('APP_SECRET');
        if (getenv('APP_ENV') !== 'test'
            || !is_string($url)
            || !str_contains($url, '/condor_test')
            || !is_string($secret)
            || strlen($secret) < 32) {
            self::markTestSkipped('Solo en MariaDB condor_test descartable con APP_SECRET efímero.');
        }
        $this->master = $secret;
        // Never use the PDO localhost socket or any non-disposable endpoint.
        // DBAL's 'url' option is not parsed when a driver is provided directly.
        $target = parse_url($url);
        if (!is_array($target)
            || ($target['scheme'] ?? '') !== 'mysql'
            || ($target['host'] ?? '') !== '127.0.0.1'
            || ($target['path'] ?? '') !== '/condor_test') {
            self::markTestSkipped('Solo se permite conectar a 127.0.0.1/condor_test.');
        }
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => rawurldecode((string) ($target['user'] ?? '')),
            'password' => rawurldecode((string) ($target['pass'] ?? '')),
            'dbname' => 'condor_test',
            'charset' => 'utf8mb4',
        ]);
        if ((int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_platform_integration_setting WHERE setting_key = ?',
            ['sentry.dsn'],
        ) !== 0) {
            self::markTestSkipped('La prueba no sobrescribe ajustes existentes.');
        }
        $this->settings = new PlatformIntegrationSettingRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->seeded && $this->settings !== null) {
            $this->settings->delete('sentry.dsn', 'sentry_boot_fixture');
        }
        $this->connection?->close();
        parent::tearDown();
    }

    public function testProdKernelResolvesEncryptedDsnWithoutEnvironmentVariable(): void
    {
        $stored = 'https://stored-fixture@sentry.invalid/123';
        $this->seed($stored);
        self::assertSame($stored, $this->bootAndReadDsn(null));
    }

    public function testProdKernelBootsWithSentryDisabledWhenNoDsnExists(): void
    {
        self::assertNull($this->bootAndReadDsn(null));
    }

    public function testProdKernelPrefersExplicitEnvironmentDsn(): void
    {
        $this->seed('https://stored-fixture@sentry.invalid/123');
        $explicit = 'https://env-fixture@sentry.invalid/456';
        self::assertSame($explicit, $this->bootAndReadDsn($explicit));
    }

    private function seed(string $dsn): void
    {
        self::assertNotNull($this->settings);
        $key = hash_hkdf('sha256', $this->master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'condor-platform-settings-v1');
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $encrypted = sodium_crypto_secretbox($dsn, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        $this->settings->put(
            'sentry.dsn',
            null,
            base64_encode($encrypted),
            base64_encode($nonce),
            1,
            substr($dsn, -4),
            'sentry_boot_fixture',
        );
        $this->seeded = true;
    }

    private function bootAndReadDsn(?string $explicit): ?string
    {
        $env = $_ENV['SENTRY_DSN'] ?? null;
        $server = $_SERVER['SENTRY_DSN'] ?? null;
        $process = getenv('SENTRY_DSN');
        $ephemeral = getenv('CONDOR_EPHEMERAL_CACHE');
        unset($_ENV['SENTRY_DSN'], $_SERVER['SENTRY_DSN']);
        if ($explicit === null) {
            putenv('SENTRY_DSN');
        } else {
            putenv('SENTRY_DSN='.$explicit);
        }
        putenv('CONDOR_EPHEMERAL_CACHE=1');

        $kernel = new Kernel('prod', false);
        try {
            $kernel->boot();
            $client = SentrySdk::getCurrentHub()->getClient();
            $dsn = $client?->getOptions()->getDsn();

            return $dsn === null ? null : (string) $dsn;
        } finally {
            $kernel->shutdown();
            if ($env === null) {
                unset($_ENV['SENTRY_DSN']);
            } else {
                $_ENV['SENTRY_DSN'] = $env;
            }
            if ($server === null) {
                unset($_SERVER['SENTRY_DSN']);
            } else {
                $_SERVER['SENTRY_DSN'] = $server;
            }
            putenv($process === false ? 'SENTRY_DSN' : 'SENTRY_DSN='.$process);
            putenv($ephemeral === false
                ? 'CONDOR_EPHEMERAL_CACHE'
                : 'CONDOR_EPHEMERAL_CACHE='.$ephemeral);
        }
    }
}
