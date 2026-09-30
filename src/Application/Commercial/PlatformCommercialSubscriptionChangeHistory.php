<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\SubscriptionChangeRecord;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformCommercialSubscriptionChangeHistory
{
    private const LIMIT = 20;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new DomainException('Tenant de historial comercial inválido.');
        }

        $records = $this->entityManager
            ->getRepository(SubscriptionChangeRecord::class)
            ->findBy(
                ['tenantId' => $tenantId],
                ['requestedAt' => 'DESC'],
                self::LIMIT,
            );

        return array_map(
            function (SubscriptionChangeRecord $record) use ($tenantId): array {
                $change = $record->toChange();
                if ($change->tenantId() !== $tenantId) {
                    throw new DomainException('Cambio comercial fuera del tenant solicitado.');
                }

                return [
                    'id' => $record->id(),
                    'direction' => $change->direction(),
                    'status' => $change->status(),
                    'current_plan' => self::plan($change->currentPlan()),
                    'target_plan' => self::plan($change->targetPlan()),
                    'requested_at' => self::time($change->requestedAt()),
                    'effective_at' => $change->effectiveAt() instanceof DateTimeImmutable
                        ? self::time($change->effectiveAt())
                        : null,
                    'blockers' => $change->blockers(),
                ];
            },
            $records,
        );
    }

    /** @return array{key:string,name:string,version:int} */
    private static function plan(\App\Domain\Commercial\Entity\PlanVersion $version): array
    {
        return [
            'key' => $version->plan()->key(),
            'name' => $version->plan()->name(),
            'version' => $version->version(),
        ];
    }

    private static function time(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
