<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use Symfony\Component\Mailer\MailerInterface;

interface MailerFactory
{
    public function create(string $dsn): MailerInterface;
}
