<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Subscription;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

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

        return [
            'status' => 'configured',
            'subscription' => [
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
                'last_changed_at' => $subscription
                    ->lastChangedAt()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\\TH:i:s.u\\Z'),
            ],
        ];
    }
}
