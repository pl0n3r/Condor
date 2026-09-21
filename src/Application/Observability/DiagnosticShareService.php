<?php

declare(strict_types=1);

namespace App\Application\Observability;

use App\Domain\Observability\Entity\DiagnosticShare;
use App\Domain\Observability\Entity\ErrorIncident;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DiagnosticShareService
{
    private const TTL_MINUTES = 30;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{share: DiagnosticShare, token: string} */
    public function create(ErrorIncident $incident): array
    {
        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $share = new DiagnosticShare(
            $incident,
            hash('sha256', $token),
            $now->add(new DateInterval('PT'.self::TTL_MINUTES.'M')),
        );

        $this->entityManager->persist($share);
        $this->entityManager->flush();

        return ['share' => $share, 'token' => $token];
    }

    public function resolve(string $token): ?DiagnosticShare
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $share = $this->entityManager
            ->getRepository(DiagnosticShare::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);

        if (!$share instanceof DiagnosticShare) {
            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $share->isUsable($now) ? $share : null;
    }

    public function revoke(DiagnosticShare $share): void
    {
        $share->revoke();
        $this->entityManager->flush();
    }
}
