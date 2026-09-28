<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Identity\Entity\User;
use DomainException;

final readonly class AccountPasswordNotifier
{
    private const CHANGE_SOURCES = ['recovery', 'authenticated_change'];

    public function __construct(
        private DeferredTransactionalEmailQueue $emailQueue,
    ) {
    }

    public function resetRequested(User $user, string $resetUrl): void
    {
        $this->emailQueue->enqueue(new TransactionalEmailMessage(
            $user->email(),
            'account_password_reset',
            [
                'reset_url' => $resetUrl,
                'expires_in_minutes' => 60,
            ],
        ));
    }

    public function passwordChanged(User $user, string $source): void
    {
        if (!in_array($source, self::CHANGE_SOURCES, true)) {
            throw new DomainException('Origen de cambio de contraseña no soportado.');
        }

        $this->emailQueue->enqueue(new TransactionalEmailMessage(
            $user->email(),
            'account_password_changed',
            ['source' => $source],
        ));
    }
}
