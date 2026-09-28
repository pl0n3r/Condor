<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Infrastructure\Notification\E2eFileTransactionalEmailGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

trait RestoresE2eMailboxEnvironment
{
    /** @var array{process:string|false,env_exists:bool,env_value:mixed,server_exists:bool,server_value:mixed} */
    private array $mailboxEnvironmentSnapshot = [];

    protected function captureMailboxEnvironment(): void
    {
        $this->mailboxEnvironmentSnapshot = [
            'process' => getenv('CONDOR_E2E_MAILBOX_PATH'),
            'env_exists' => array_key_exists('CONDOR_E2E_MAILBOX_PATH', $_ENV),
            'env_value' => $_ENV['CONDOR_E2E_MAILBOX_PATH'] ?? null,
            'server_exists' => array_key_exists('CONDOR_E2E_MAILBOX_PATH', $_SERVER),
            'server_value' => $_SERVER['CONDOR_E2E_MAILBOX_PATH'] ?? null,
        ];
    }

    protected function restoreMailboxEnvironment(): void
    {
        $snapshot = $this->mailboxEnvironmentSnapshot;
        $process = $snapshot['process'];

        if (is_string($process)) {
            putenv('CONDOR_E2E_MAILBOX_PATH='.$process);
        } else {
            putenv('CONDOR_E2E_MAILBOX_PATH');
        }

        if ($snapshot['env_exists']) {
            $_ENV['CONDOR_E2E_MAILBOX_PATH'] = $snapshot['env_value'];
        } else {
            unset($_ENV['CONDOR_E2E_MAILBOX_PATH']);
        }

        if ($snapshot['server_exists']) {
            $_SERVER['CONDOR_E2E_MAILBOX_PATH'] = $snapshot['server_value'];
        } else {
            unset($_SERVER['CONDOR_E2E_MAILBOX_PATH']);
        }
    }
}

final class E2eFileTransactionalEmailGatewayTest extends TestCase
{
    use RestoresE2eMailboxEnvironment;

    private ?string $mailbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureMailboxEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->mailbox !== null) {
                @unlink($this->mailbox);
            }
        } finally {
            $this->restoreMailboxEnvironment();
            parent::tearDown();
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

    public function testItRejectsPreexistingInsecureMailboxWithoutWriting(): void
    {
        $this->mailbox = self::mailbox();
        file_put_contents($this->mailbox, "sentinel\n");
        chmod($this->mailbox, 0644);
        putenv('CONDOR_E2E_MAILBOX_PATH='.$this->mailbox);

        $gateway = new E2eFileTransactionalEmailGateway('test');
        $this->expectException(RuntimeException::class);

        try {
            $gateway->deliver(self::message());
        } finally {
            self::assertSame("sentinel\n", file_get_contents($this->mailbox));
        }
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
    use RestoresE2eMailboxEnvironment;

    private ?string $mailbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureMailboxEnvironment();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->mailbox !== null) {
                @unlink($this->mailbox);
            }
        } finally {
            $this->restoreMailboxEnvironment();
            parent::tearDown();
        }
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
