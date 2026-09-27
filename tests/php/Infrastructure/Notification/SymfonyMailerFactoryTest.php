<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Notification;

use App\Infrastructure\Notification\SymfonyMailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\MailerInterface;

final class SymfonyMailerFactoryTest extends TestCase
{
    public function testItBuildsMailerFromServerSideDsnWithoutSending(): void
    {
        $mailer = (new SymfonyMailerFactory())->create('null://null');

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function testItRejectsUnsupportedTransportDsn(): void
    {
        $this->expectException(UnsupportedSchemeException::class);

        (new SymfonyMailerFactory())->create('unsupported://default');
    }
}
