<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

final class SymfonyMailerFactory implements MailerFactory
{
    public function create(string $dsn): MailerInterface
    {
        return new Mailer(Transport::fromDsn($dsn));
    }
}
