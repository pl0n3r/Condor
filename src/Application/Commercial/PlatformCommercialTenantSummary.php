<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\SubscriptionState;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlatformCommercialTenantSummary
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{status:string, subscription:array<string,mixed>|null} */
    public function forTenant(string $tenantId): array
    {
        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);

        if (!$subscription instanceof Subscription) {
            return ['status' => 'not_configured', 'subscription' => null];
        }

        $version = $subscription->planVersion();
        $payload = [
            'state' => $subscription->state()->value,
            'plan' => [
                'key' => $version->plan()->key(),
                'name' => $version->plan()->name(),
                'version' => $version->version(),
                'currency' => $version->currency(),
                'monthly_amount' => $version->monthlyAmount(),
                'annual_amount' => $version->annualAmount(),
                'quote_required' => $version->quoteRequired(),
            ],
            'last_changed_at' => self::formatUtc($subscription->lastChangedAt()),
        ];

        if ($subscription->state() === SubscriptionState::Trialing) {
            $lifecycle = $subscription->toLifecycle();
            $startedAt = $lifecycle->trialStartedAt();
            $endsAt = $lifecycle->trialEndsAt();
            if ($startedAt === null || $endsAt === null) {
                throw new DomainException(
                    'Suscripción trialing sin ventana trial canónica.',
                );
            }

            $payload['trial_started_at'] = self::formatUtc($startedAt);
            $payload['trial_ends_at'] = self::formatUtc($endsAt);
        }

        return [
            'status' => 'configured',
            'subscription' => $payload,
        ];
    }

    private static function formatUtc(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
