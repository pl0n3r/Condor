<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\MailerFactory;
use App\Infrastructure\Notification\SymfonyMailerTransactionalEmailGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SymfonyMailerTransactionalEmailGatewayTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (['CONDOR_MAILER_DSN', 'CONDOR_MAIL_FROM', 'CONDOR_MAIL_PROVIDER'] as $name) {
            putenv($name);
        }

        foreach ($this->temporaryDirectories as $directory) {
            @unlink($directory.'/datos.yml');
            @rmdir($directory);
        }
    }

    public function testItBuildsAndSendsResetEmailOnlyForDocumentedProvider(): void
    {
        putenv('CONDOR_MAILER_DSN=smtp://mailer:secret@smtp.example.test:2525');
        putenv('CONDOR_MAIL_FROM=security@condor.example.test');
        putenv('CONDOR_MAIL_PROVIDER=test-smtp');

        $mailer = new CapturingMailer();
        $factory = new CapturingMailerFactory($mailer);
        $gateway = new SymfonyMailerTransactionalEmailGateway(
            $factory,
            $this->projectDir(['test-smtp']),
        );

        $gateway->deliver(new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_reset',
            [
                'reset_url' => 'https://condor.example.test/admin/restablecer-contrasena#token=abc123',
                'expires_in_minutes' => 60,
            ],
        ));

        self::assertSame(
            ['smtp://mailer:secret@smtp.example.test:2525'],
            $factory->dsns,
        );
        self::assertCount(1, $mailer->messages);
        $email = $mailer->messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('security@condor.example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('admin@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('Restablece tu contraseña de Condor', $email->getSubject());
        self::assertStringContainsString('#token=abc123', (string) $email->getTextBody());
    }

    public function testItFailsClosedWhenProviderIsNotDocumented(): void
    {
        putenv('CONDOR_MAILER_DSN=smtp://smtp.example.test');
        putenv('CONDOR_MAIL_FROM=security@condor.example.test');
        putenv('CONDOR_MAIL_PROVIDER=unknown-provider');

        $gateway = new SymfonyMailerTransactionalEmailGateway(
            new CapturingMailerFactory(new CapturingMailer()),
            $this->projectDir([]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no está documentado');
        $gateway->deliver(new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_changed',
            ['source' => 'profile'],
        ));
    }

    public function testItRejectsNonSmtpTransports(): void
    {
        putenv('CONDOR_MAILER_DSN=sendmail://default');
        putenv('CONDOR_MAIL_FROM=security@condor.example.test');
        putenv('CONDOR_MAIL_PROVIDER=test-smtp');

        $gateway = new SymfonyMailerTransactionalEmailGateway(
            new CapturingMailerFactory(new CapturingMailer()),
            $this->projectDir(['test-smtp']),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP/SMTPS');
        $gateway->deliver(new TransactionalEmailMessage(
            'admin@example.test',
            'account_password_changed',
            ['source' => 'profile'],
        ));
    }

    /** @param list<string> $providers */
    private function projectDir(array $providers): string
    {
        $directory = sys_get_temp_dir().'/condor-mailer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;

        file_put_contents(
            $directory.'/datos.yml',
            json_encode(
                [
                    'treatments' => [[
                        'id' => 'condor_password_reset',
                        'providers' => $providers,
                    ]],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        return $directory;
    }
}

final class CapturingMailerFactory implements MailerFactory
{
    /** @var list<string> */
    public array $dsns = [];

    public function __construct(private readonly CapturingMailer $mailer)
    {
    }

    public function create(string $dsn): MailerInterface
    {
        $this->dsns[] = $dsn;

        return $this->mailer;
    }
}

final class CapturingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }
}
