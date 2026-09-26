<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\AccountPasswordNotifier;
use App\Application\Identity\PasswordResetUrlFactory;
use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Domain\Identity\Entity\User;
use PHPUnit\Framework\TestCase;

final class AccountPasswordNotifierTest extends TestCase
{
    public function testItCentralizesResetAndChangedMessagesWithoutPersistingSecrets(): void
    {
        $queue = new DeferredTransactionalEmailQueue();
        $notifier = new AccountPasswordNotifier(
            $queue,
            new PasswordResetUrlFactory('https://secure.example.test'),
        );
        $user = new User('admin@example.test', 'Admin');
        $token = str_repeat('a', 64);

        $notifier->resetRequested($user, $token);
        $notifier->passwordChanged($user, 'recovery');

        $messages = $queue->drain();
        self::assertCount(2, $messages);
        self::assertSame('account_password_reset', $messages[0]->templateKey);
        self::assertStringEndsWith(
            '#token='.$token,
            (string) $messages[0]->templateData['reset_url'],
        );
        self::assertSame('account_password_changed', $messages[1]->templateKey);
        self::assertSame('recovery', $messages[1]->templateData['source']);
    }
}
