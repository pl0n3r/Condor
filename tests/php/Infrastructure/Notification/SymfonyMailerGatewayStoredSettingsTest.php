<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\MailProviderConfiguration;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\MailerFactory;
use App\Infrastructure\Notification\SymfonyMailerTransactionalEmailGateway;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** No real credentials, provider accounts, mail delivery or persistent settings. */
final class SymfonyMailerGatewayStoredSettingsTest extends TestCase
{
    private string $master;
    /** @var list<string> */
    private array $temporaryDirs = [];

    protected function setUp(): void
    {
        $this->master = bin2hex(random_bytes(32));
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        foreach ($this->temporaryDirs as $dir) {
            @unlink($dir.'/datos.yml');
            @rmdir($dir);
        }
    }

    public function testStoredConfigurationWorksWithoutEnvironmentAndEncodesTheKey(): void
    {
        $secret = 'synthetic:+/@?=key-1234';
        $rows = $this->settings('resend', 'owner@condor.example.test', $secret);
        $factory = new StoredMailCapturingFactory();
        $gateway = $this->gateway($rows, $factory, ['resend']);

        self::assertTrue($gateway->isAvailable());
        $gateway->deliver(self::message());
        self::assertSame(
            ['smtps://resend:'.rawurlencode($secret).'@smtp.resend.com:465'],
            $factory->dsns,
        );
        self::assertCount(1, $factory->emails);
        self::assertSame('owner@condor.example.test', $factory->emails[0]->getFrom()[0]->getAddress());
    }

    public function testStoredConfigurationWinsOverEnvironment(): void
    {
        putenv('CONDOR_MAILER_DSN=smtp://env:environment-password@smtp.example.test');
        putenv('CONDOR_MAIL_PROVIDER=fixture-smtp');
        putenv('CONDOR_MAIL_FROM=environment@example.test');
        $factory = new StoredMailCapturingFactory();
        $gateway = $this->gateway(
            $this->settings('resend', 'owner@example.test', 'stored-synthetic-key-1234'),
            $factory,
            ['resend', 'fixture-smtp'],
        );
        $gateway->deliver(self::message());

        self::assertSame(['smtps://resend:stored-synthetic-key-1234@smtp.resend.com:465'], $factory->dsns);
        self::assertSame('owner@example.test', $factory->emails[0]->getFrom()[0]->getAddress());
    }

    public function testMissingConfigurationAndUnlistedProviderFailClosed(): void
    {
        $factory = new StoredMailCapturingFactory();
        $missing = $this->gateway([], $factory, ['resend']);
        self::assertFalse($missing->isAvailable());
        $this->assertUnavailable($missing, 'synthetic-secret');

        $unlisted = $this->gateway(
            $this->settings('resend', 'owner@example.test', 'synthetic-secret-1234'),
            $factory,
            [],
        );
        self::assertFalse($unlisted->isAvailable());
        $this->assertUnavailable($unlisted, 'synthetic-secret-1234');
        self::assertSame([], $factory->dsns);
    }

    public function testMalformedStoredSecretFailsClosedWithoutFallingBackToEnvironment(): void
    {
        $rows = $this->settings('resend', 'owner@example.test', 'synthetic-secret-1234');
        $rows['mail.api_key']['nonce'] = base64_encode('wrong-nonce');
        $this->environmentFallback();

        $factory = new StoredMailCapturingFactory();
        $gateway = $this->gateway($rows, $factory, ['resend']);
        self::assertFalse($gateway->isAvailable());
        $this->assertUnavailable($gateway, 'synthetic-secret-1234');
        self::assertSame([], $factory->dsns);
    }

    public function testUnavailableStorageUsesEnvironmentFallback(): void
    {
        $this->environmentFallback();
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(
            new RuntimeException('synthetic-storage-error-with-no-sensitive-values'),
        );
        $factory = new StoredMailCapturingFactory();
        $gateway = new SymfonyMailerTransactionalEmailGateway(
            $factory, $this->projectDir(['resend']), new MailProviderConfiguration($connection, $this->master),
        );
        $gateway->deliver(self::message());
        self::assertSame(['smtp://fixture:synthetic@smtp.example.test'], $factory->dsns);
    }

    public function testTransportExceptionDoesNotContainTheDecryptedKeyOrChain(): void
    {
        $secret = 'confidential-synthetic-key-1234';
        $factory = new StoredMailCapturingFactory(true);
        $gateway = $this->gateway($this->settings('resend', 'owner@example.test', $secret), $factory, ['resend']);

        try {
            $gateway->deliver(self::message());
            self::fail('Transport failure must be redacted.');
        } catch (RuntimeException $error) {
            self::assertSame('No se pudo entregar el correo transaccional.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($secret, $error->getMessage());
            self::assertStringNotContainsString($secret, $error->getTraceAsString());
        }
    }

    /** @param array<string, array<string, mixed>> $rows
     *  @param list<string> $providers
     */
    private function gateway(array $rows, StoredMailCapturingFactory $factory, array $providers): SymfonyMailerTransactionalEmailGateway
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $params) use ($rows): array|false {
                self::assertStringContainsString('WHERE setting_key = ?', $sql);
                self::assertCount(1, $params);
                self::assertContains($params[0], ['mail.provider', 'mail.from', 'mail.api_key']);
                return $rows[$params[0]] ?? false;
            });
        return new SymfonyMailerTransactionalEmailGateway(
            $factory,
            $this->projectDir($providers),
            new MailProviderConfiguration($connection, $this->master),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function settings(string $provider, string $from, string $apiKey): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = hash_hkdf('sha256', $this->master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'condor-platform-settings-v1');
        try {
            $cipher = sodium_crypto_secretbox($apiKey, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }
        return [
            'mail.provider' => self::plainRow($provider),
            'mail.from' => self::plainRow($from),
            'mail.api_key' => [
                'plain_value' => null,
                'encrypted_value' => base64_encode($cipher),
                'nonce' => base64_encode($nonce),
                'key_version' => 1,
                'secret_suffix' => substr($apiKey, -4),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function plainRow(string $value): array
    {
        return [
            'plain_value' => $value,
            'encrypted_value' => null,
            'nonce' => null,
            'key_version' => null,
            'secret_suffix' => null,
        ];
    }

    private static function message(): TransactionalEmailMessage
    {
        return new TransactionalEmailMessage('recipient@example.test', 'account_password_changed', []);
    }

    private function assertUnavailable(SymfonyMailerTransactionalEmailGateway $gateway, string $secret): void
    {
        try {
            $gateway->deliver(self::message());
            self::fail('An unavailable gateway must fail closed.');
        } catch (RuntimeException $error) {
            self::assertSame('El correo transaccional no está disponible.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($secret, $error->getMessage());
        }
    }

    private function environmentFallback(): void
    {
        putenv('CONDOR_MAILER_DSN=smtp://fixture:synthetic@smtp.example.test');
        putenv('CONDOR_MAIL_FROM=environment@example.test');
        putenv('CONDOR_MAIL_PROVIDER=resend');
    }

    private function clearEnvironment(): void
    {
        foreach (['CONDOR_MAILER_DSN', 'CONDOR_MAIL_FROM', 'CONDOR_MAIL_PROVIDER'] as $variable) {
            putenv($variable);
        }
    }

    /** @param list<string> $providers */
    private function projectDir(array $providers): string
    {
        $dir = sys_get_temp_dir().'/condor-mail-settings-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $this->temporaryDirs[] = $dir;
        file_put_contents($dir.'/datos.yml', json_encode([
            'treatments' => [['id' => 'condor_password_reset', 'providers' => $providers]],
        ], JSON_THROW_ON_ERROR));
        return $dir;
    }
}

final class StoredMailCapturingFactory implements MailerFactory
{
    /** @var list<string> */
    public array $dsns = [];
    /** @var list<Email> */
    public array $emails = [];

    public function __construct(private bool $fail = false)
    {
    }

    public function create(string $dsn): MailerInterface
    {
        $this->dsns[] = $dsn;
        if ($this->fail) {
            throw new RuntimeException('synthetic transport failure includes '.$dsn);
        }
        return new class($this) implements MailerInterface {
            public function __construct(private StoredMailCapturingFactory $factory)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->factory->emails[] = $message;
                }
            }
        };
    }
}
