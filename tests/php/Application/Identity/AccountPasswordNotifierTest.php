<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\AccountPasswordNotifier;
use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Domain\Identity\Entity\User;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AccountPasswordNotifierTest extends TestCase
{
    public function testItQueuesResetAndChangeTemplatesWithoutOwningUrlConstruction(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $notifier = new AccountPasswordNotifier($queue);
        $user = new User('admin@example.test', 'Admin');
        $resetUrl = 'https://secure.example.test/admin/restablecer-contrasena#token='.str_repeat('a', 64);

        $notifier->resetRequested($user, $resetUrl);
        $notifier->passwordChanged($user, 'recovery');

        $messages = $queue->drain();
        self::assertCount(2, $messages);
        self::assertSame('admin@example.test', $messages[0]->recipient);
        self::assertSame('account_password_reset', $messages[0]->templateKey);
        self::assertSame($resetUrl, $messages[0]->templateData['reset_url']);
        self::assertSame(60, $messages[0]->templateData['expires_in_minutes']);
        self::assertSame('account_password_changed', $messages[1]->templateKey);
        self::assertSame('recovery', $messages[1]->templateData['source']);
        self::assertSame([], $queue->drain());
    }

    public function testItRejectsUnknownChangeSourceBeforeQueueing(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $notifier = new AccountPasswordNotifier($queue);
        $user = new User('admin@example.test', 'Admin');

        try {
            $notifier->passwordChanged($user, 'secret-or-user-controlled-value');
            self::fail('Un origen no soportado debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Origen de cambio de contraseña no soportado.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $queue->drain());
    }
}
