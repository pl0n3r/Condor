<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Identity\Entity\User;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\Entity\NotificationPreference;
use App\Domain\Notification\NotificationCatalog;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationPreferenceService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function isEnabled(
        User $user,
        string $eventKey,
        string $channelKey,
    ): bool {
        $eventKey = NotificationCatalog::normalizeEvent($eventKey);
        $channelKey = NotificationCatalog::normalizeChannel($channelKey);

        if (NotificationCatalog::isMandatory($eventKey)) {
            return true;
        }

        $preference = $this->entityManager
            ->getRepository(NotificationPreference::class)
            ->findOneBy([
                'user' => $user,
                'eventKey' => $eventKey,
                'channelKey' => $channelKey,
            ]);

        return !$preference instanceof NotificationPreference
            || $preference->isEnabled();
    }

    public function set(
        User $user,
        string $eventKey,
        string $channelKey,
        bool $enabled,
    ): NotificationPreference {
        $eventKey = NotificationCatalog::normalizeEvent($eventKey);
        $channelKey = NotificationCatalog::normalizeChannel($channelKey);

        $repository = $this->entityManager
            ->getRepository(NotificationPreference::class);
        $preference = $repository->findOneBy([
            'user' => $user,
            'eventKey' => $eventKey,
            'channelKey' => $channelKey,
        ]);

        if (!$preference instanceof NotificationPreference) {
            $preference = new NotificationPreference(
                $user,
                $eventKey,
                $channelKey,
                $enabled,
            );
            $this->entityManager->persist($preference);
        } else {
            $preference->setEnabled($enabled);
        }

        $this->entityManager->flush();

        return $preference;
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(
        User $user,
        string $eventKey,
        string $channelKey,
        array $payload = [],
    ): ?NotificationDelivery {
        if (!$this->isEnabled($user, $eventKey, $channelKey)) {
            return null;
        }

        $delivery = new NotificationDelivery(
            $user,
            $eventKey,
            $channelKey,
            $payload,
        );
        $this->entityManager->persist($delivery);
        $this->entityManager->flush();

        return $delivery;
    }
}
