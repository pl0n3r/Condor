<?php

declare(strict_types=1);

namespace App\Application\Notification;

final class DeferredTransactionalEmailQueue
{
    /** @var list<TransactionalEmailMessage> */
    private array $messages = [];

    public function enqueue(TransactionalEmailMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<TransactionalEmailMessage> */
    public function drain(): array
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }
}
