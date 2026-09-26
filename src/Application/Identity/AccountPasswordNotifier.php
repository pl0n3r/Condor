<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Identity\Entity\User;

final readonly class AccountPasswordNotifier
{
    public function __construct(
        private DeferredTransactionalEmailQueue $emailQueue,
        private PasswordResetUrlFactory $resetUrlFactory,
    ) {
    }

    public function resetRequested(User $user, string $rawToken): void
    {
        $this->emailQueue->enqueue(new TransactionalEmailMessage(
            $user->email(),
            'account_password_reset',
            [
                'reset_url' => $this->resetUrlFactory->resetUrl($rawToken),
                'expires_in_minutes' => 60,
            ],
        ));
    }

    public function passwordChanged(User $user, string $source): void
    {
        $this->emailQueue->enqueue(new TransactionalEmailMessage(
            $user->email(),
            'account_password_changed',
            ['source' => $source],
        ));
    }
}
