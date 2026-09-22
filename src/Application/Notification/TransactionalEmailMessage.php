<?php

declare(strict_types=1);

namespace App\Application\Notification;

final readonly class TransactionalEmailMessage
{
    /** @param array<string, mixed> $templateData */
    public function __construct(
        public string $recipient,
        public string $templateKey,
        public array $templateData,
    ) {
    }
}
