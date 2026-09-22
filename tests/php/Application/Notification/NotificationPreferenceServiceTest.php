<?php

declare(strict_types=1);

namespace App\Tests\Application\Notification;

use App\Application\Notification\NotificationPreferenceService;
use App\Domain\Identity\Entity\User;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\Entity\NotificationPreference;
use App\Domain\Notification\NotificationCatalog;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationPreferenceServiceTest extends WebTestCase
{
    public function testOptionalNotificationCanBeDisabledPerUserAndChannel(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'notify-optional-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Notificaciones',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $service = new NotificationPreferenceService($entityManager);
        self::assertTrue($service->isEnabled(
            $user,
            NotificationCatalog::EVENT_ORDER_CREATED,
            NotificationCatalog::CHANNEL_EMAIL,
        ));

        $preference = $service->set(
            $user,
            NotificationCatalog::EVENT_ORDER_CREATED,
            NotificationCatalog::CHANNEL_EMAIL,
            false,
        );

        self::assertFalse($preference->isEnabled());
        self::assertFalse($service->isEnabled(
            $user,
            NotificationCatalog::EVENT_ORDER_CREATED,
            NotificationCatalog::CHANNEL_EMAIL,
        ));
        self::assertNull($service->enqueue(
            $user,
            NotificationCatalog::EVENT_ORDER_CREATED,
            NotificationCatalog::CHANNEL_EMAIL,
            ['order_id' => 'ORDER-TEST'],
        ));
        self::assertSame(
            0,
            $entityManager->getRepository(NotificationDelivery::class)
                ->count([
                    'user' => $user,
                    'eventKey' => NotificationCatalog::EVENT_ORDER_CREATED,
                ]),
        );
    }

    public function testMandatorySecurityNotificationCannotBeDisabled(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'notify-mandatory-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Seguridad',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $service = new NotificationPreferenceService($entityManager);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Las notificaciones obligatorias de seguridad no se pueden desactivar.',
        );

        $service->set(
            $user,
            NotificationCatalog::EVENT_ACCOUNT_INVITATION,
            NotificationCatalog::CHANNEL_EMAIL,
            false,
        );
    }

    public function testMandatoryNotificationQueuesWithoutStoredPreference(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'notify-queue-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Cola',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $service = new NotificationPreferenceService($entityManager);
        $delivery = $service->enqueue(
            $user,
            NotificationCatalog::EVENT_PASSWORD_RESET,
            NotificationCatalog::CHANNEL_IN_APP,
            ['source' => 'security'],
        );

        self::assertInstanceOf(NotificationDelivery::class, $delivery);
        self::assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status());
        self::assertSame(
            NotificationCatalog::EVENT_PASSWORD_RESET,
            $delivery->eventKey(),
        );
        self::assertSame(
            0,
            $entityManager->getRepository(NotificationPreference::class)
                ->count(['user' => $user]),
        );
    }
}
