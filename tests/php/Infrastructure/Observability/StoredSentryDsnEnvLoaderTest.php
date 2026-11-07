<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\StoredSentryDsnEnvLoader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class StoredSentryDsnEnvLoaderTest extends TestCase
{
    private string $master;

    protected function setUp(): void
    {
        $this->master = bin2hex(random_bytes(32));
    }

    public function testEncryptedOwnerDsnOverridesAnExistingEnvironmentDsn(): void
    {
        $dsn = 'https://fixture-key@fixture.ingest.sentry.io/123';
        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchAssociative')
            ->willReturnCallback(function (string $sql, array $params) use ($dsn, &$calls): array {
                ++$calls;
                self::assertStringContainsString('WHERE setting_key = ?', $sql);
                self::assertSame(['sentry.dsn'], $params);
                return $this->encryptedRow($dsn);
            });
        $loader = $this->loader($db);
        self::assertInstanceOf(EnvVarLoaderInterface::class, $loader);
        self::assertInstanceOf(EnvVarProcessorInterface::class, $loader);
        self::assertSame(['string' => 'string'], $loader::getProvidedTypes());

        $this->withEnvironmentDsn('https://env-key@backup.ingest.sentry.io/456', function () use ($loader, $dsn): void {
            self::assertSame($dsn, $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));
            self::assertSame($dsn, $loader->getEnv('string', 'SENTRY_DSN', $this->envGetter()));
        });
        self::assertSame(1, $calls, 'Debe consultar una sola vez el almacén.');
    }

    public function testShortProcessCacheRefreshesAfterExpiry(): void
    {
        $first = 'https://fixture-one@fixture.ingest.sentry.io/11';
        $second = 'https://fixture-two@fixture.ingest.sentry.io/22';
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls($this->encryptedRow($first), $this->encryptedRow($second));
        $loader = $this->loader($db);
        self::assertSame($first, $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));
        self::assertSame($first, $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));

        // Simulate passage of time without waiting or mutating the clock.
        $expiry = new \ReflectionProperty($loader, 'cacheExpiresAt');
        $expiry->setValue($loader, time() - 1);
        self::assertSame($second, $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));
    }

    public function testStoredDsnWinsWithNoEnvironmentVariable(): void
    {
        $dsn = 'https://fixture-key@fixture.ingest.sentry.io/123';
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->encryptedRow($dsn));
        $this->withEnvironmentDsn(null, function () use ($db, $dsn): void {
            self::assertSame($dsn, $this->loader($db)->getEnv('', 'SENTRY_DSN', $this->envGetter()));
        });
    }

    public function testFallbackUsesEnvironmentAndReturnsEmptyWhenBothAreAbsent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);
        $loader = $this->loader($db);
        $this->withEnvironmentDsn('https://backup.ingest.sentry.io/321', function () use ($loader): void {
            self::assertSame('https://backup.ingest.sentry.io/321', $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));
        });
        $this->withEnvironmentDsn(null, function () use ($loader): void {
            self::assertSame('', $loader->getEnv('', 'SENTRY_DSN', $this->envGetter()));
        });
    }

    public function testDatabaseFailureDoesNotThrowAndUsesEnvironment(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willThrowException(new \RuntimeException('synthetic-db-down'));
        $this->withEnvironmentDsn('https://backup.ingest.sentry.io/321', function () use ($db): void {
            self::assertSame(
                'https://backup.ingest.sentry.io/321',
                $this->loader($db)->getEnv('', 'SENTRY_DSN', $this->envGetter()),
            );
        });
    }

    public function testDamagedCiphertextOrPlaceholderMasterNeverProducesDsn(): void
    {
        $dsn = 'https://fixture-key@fixture.ingest.sentry.io/123';
        $db = $this->createMock(Connection::class);
        $invalid = $this->encryptedRow($dsn);
        $invalid['nonce'] = base64_encode('invalid-nonce');
        $db->method('fetchAssociative')->willReturn($invalid);
        $this->withEnvironmentDsn(null, function () use ($db): void {
            self::assertSame('', $this->loader($db)->getEnv('', 'SENTRY_DSN', $this->envGetter()));
        });

        $placeholderDb = $this->createMock(Connection::class);
        $placeholderDb->expects(self::never())->method('fetchAssociative');
        $this->withEnvironmentDsn(null, function () use ($placeholderDb): void {
            self::assertSame('', $this->loader($placeholderDb)->getEnv(
                '', 'SENTRY_DSN', static fn (string $key): string => 'APP_SECRET' === $key ? 'example-app-secret' : '',
            ));
        });
    }

    public function testOnlySentryDsnIsLoadedAndUnrelatedEnvIsUntouched(): void
    {
        $dsn = 'https://fixture-key@fixture.ingest.sentry.io/123';
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->encryptedRow($dsn));
        $loader = $this->loader($db);
        $original = $_ENV['APP_SECRET'] ?? null;
        $_ENV['APP_SECRET'] = $this->master;
        try {
            self::assertSame(['SENTRY_DSN' => $dsn], $loader->loadEnvVars());
            $unrelated = $_ENV['UNRELATED_CONFIGURATION'] ?? null;
            $_ENV['UNRELATED_CONFIGURATION'] = 'unrelated-fixture';
            try {
                self::assertSame('unrelated-fixture', $loader->getEnv(
                    '', 'UNRELATED_CONFIGURATION', static fn (string $key): string => '',
                ));
            } finally {
                if (null === $unrelated) {
                    unset($_ENV['UNRELATED_CONFIGURATION']);
                } else {
                    $_ENV['UNRELATED_CONFIGURATION'] = $unrelated;
                }
            }
            // The DSN loader returns exactly one allowlisted key, never APP_SECRET.
            self::assertSame(['SENTRY_DSN'], array_keys($loader->loadEnvVars()));
        } finally {
            if ($original === null) {
                unset($_ENV['APP_SECRET']);
            } else {
                $_ENV['APP_SECRET'] = $original;
            }
        }
    }

    private function loader(Connection $db): StoredSentryDsnEnvLoader
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->willReturnCallback(
            static fn (string $name): bool => 'env(SENTRY_DSN)' === $name,
        );
        $container->method('getParameter')->willReturnCallback(
            static fn (string $name): string => 'env(SENTRY_DSN)' === $name ? '' : throw new \LogicException('Parámetro inesperado.'),
        );

        return new StoredSentryDsnEnvLoader($container, static fn (): Connection => $db);
    }

    /** @return \Closure(string):string */
    private function envGetter(): \Closure
    {
        return fn (string $key): string => 'APP_SECRET' === $key ? $this->master : '';
    }

    /** @return array<string,mixed> */
    private function encryptedRow(string $dsn): array
    {
        $key = hash_hkdf('sha256', $this->master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'condor-platform-settings-v1');
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($dsn, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return [
            'plain_value' => null,
            'encrypted_value' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'key_version' => 1,
            'secret_suffix' => substr($dsn, -4),
        ];
    }

    /** @param \Closure():void $assertions */
    private function withEnvironmentDsn(?string $value, \Closure $assertions): void
    {
        $oldEnv = $_ENV['SENTRY_DSN'] ?? null;
        $oldServer = $_SERVER['SENTRY_DSN'] ?? null;
        $oldProcess = getenv('SENTRY_DSN');
        unset($_ENV['SENTRY_DSN'], $_SERVER['SENTRY_DSN']);
        if ($value === null) {
            putenv('SENTRY_DSN');
        } else {
            $_ENV['SENTRY_DSN'] = $value;
            putenv('SENTRY_DSN='.$value);
        }
        try {
            $assertions();
        } finally {
            if ($oldEnv !== null) {
                $_ENV['SENTRY_DSN'] = $oldEnv;
            }
            if ($oldServer !== null) {
                $_SERVER['SENTRY_DSN'] = $oldServer;
            }
            if ($oldProcess === false) {
                putenv('SENTRY_DSN');
            } else {
                putenv('SENTRY_DSN='.$oldProcess);
            }
        }
    }
}
