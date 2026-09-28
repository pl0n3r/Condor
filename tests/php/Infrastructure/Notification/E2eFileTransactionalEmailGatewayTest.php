<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\E2eFileTransactionalEmailGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class E2eFileTransactionalEmailGatewayTest extends TestCase
{
    private ?string $mailbox = null;

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('CONDOR_E2E_MAILBOX_PATH');

        if ($this->mailbox !== null) {
            @unlink($this->mailbox);
        }
    }

    public function testItIsFailClosedOutsideExplicitTestMailbox(): void
    {
        $this->mailbox = sys_get_temp_dir().'/condor-e2e-mail-'.bin2hex(random_bytes(6)).'.jsonl';
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);
        putenv('APP_ENV=prod');

        $gateway = new E2eFileTransactionalEmailGateway();
        self::assertFalse($gateway->isAvailable());

        $this->expectException(RuntimeException::class);
        $gateway->deliver(new TransactionalEmailMessage(
            'recovery-e2e@example.test',
            'account_password_reset',
            ['reset_url' => 'https://example.test/#token='.str_repeat('a', 64)],
        ));
    }

    public function testItCapturesSyntheticMessageOnlyInEphemeralTestMailbox(): void
    {
        $this->mailbox = sys_get_temp_dir().'/condor-e2e-mail-'.bin2hex(random_bytes(6)).'.jsonl';
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);
        putenv('APP_ENV=test');

        $gateway = new E2eFileTransactionalEmailGateway();
        self::assertTrue($gateway->isAvailable());

        $gateway->deliver(new TransactionalEmailMessage(
            'recovery-e2e@example.test',
            'account_password_reset',
            [
                'reset_url' => 'https://www.condorapp.com.co/admin/restablecer-contrasena#token='.str_repeat('b', 64),
                'expires_in_minutes' => 60,
            ],
        ));

        $raw = file_get_contents($this->mailbox);
        self::assertIsString($raw);
        $record = json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('recovery-e2e@example.test', $record['recipient']);
        self::assertSame('account_password_reset', $record['templateKey']);
        self::assertStringContainsString('#token=', $record['templateData']['reset_url']);
        self::assertSame(0600, fileperms($this->mailbox) & 0777);
    }
}
