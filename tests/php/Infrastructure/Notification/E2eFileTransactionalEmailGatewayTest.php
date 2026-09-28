<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\E2eFileTransactionalEmailGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class E2eFileTransactionalEmailGatewayTest extends TestCase
{
    private ?string $mailbox = null;

    protected function tearDown(): void
    {
        putenv('CONDOR_E2E_MAILBOX_PATH');
        unset($_ENV['CONDOR_E2E_MAILBOX_PATH'], $_SERVER['CONDOR_E2E_MAILBOX_PATH']);

        if ($this->mailbox !== null) {
            @unlink($this->mailbox);
        }
    }

    public function testItIsFailClosedOutsideTestEnvironment(): void
    {
        $this->mailbox = self::mailbox();
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);

        $gateway = new E2eFileTransactionalEmailGateway('prod');
        self::assertFalse($gateway->isAvailable());

        $this->expectException(RuntimeException::class);
        $gateway->deliver(self::message());
    }

    public function testItCapturesSyntheticMessageOnlyInEphemeralTestMailbox(): void
    {
        $this->mailbox = self::mailbox();
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);

        $gateway = new E2eFileTransactionalEmailGateway('test');
        self::assertTrue($gateway->isAvailable());
        $gateway->deliver(self::message());

        $raw = file_get_contents($this->mailbox);
        self::assertIsString($raw);
        $record = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('recovery-e2e@example.test', $record['recipient']);
        self::assertSame('account_password_reset', $record['templateKey']);
        self::assertStringContainsString('#token=', $record['templateData']['reset_url']);
        self::assertSame(0600, fileperms($this->mailbox) & 0777);
    }

    private static function mailbox(): string
    {
        return sys_get_temp_dir().'/condor-e2e-mail-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    private static function message(): TransactionalEmailMessage
    {
        return new TransactionalEmailMessage(
            'recovery-e2e@example.test',
            'account_password_reset',
            [
                'reset_url' => 'https://www.condorapp.com.co/admin/restablecer-contrasena#token='.str_repeat('b', 64),
                'expires_in_minutes' => 60,
            ],
        );
    }
}

final class E2eFileTransactionalEmailGatewayContainerTest extends KernelTestCase
{
    private ?string $mailbox = null;

    protected function tearDown(): void
    {
        putenv('CONDOR_E2E_MAILBOX_PATH');
        unset($_ENV['CONDOR_E2E_MAILBOX_PATH'], $_SERVER['CONDOR_E2E_MAILBOX_PATH']);
        if ($this->mailbox !== null) {
            @unlink($this->mailbox);
        }

        parent::tearDown();
    }

    public function testTestContainerOverridesTransactionalGateway(): void
    {
        $this->mailbox = sys_get_temp_dir().'/condor-e2e-container-'.bin2hex(random_bytes(6)).'.jsonl';
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);
        $_SERVER['CONDOR_E2E_MAILBOX_PATH'] = $this->mailbox;

        self::bootKernel(['environment' => 'test']);
        $gateway = static::getContainer()->get(TransactionalEmailGateway::class);

        self::assertInstanceOf(E2eFileTransactionalEmailGateway::class, $gateway);
        self::assertTrue($gateway->isAvailable());
    }
}
