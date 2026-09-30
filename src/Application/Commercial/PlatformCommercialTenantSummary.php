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

    /**
     * @return array{
     *   status: 'configured',
     *   subscription: array{
     *     state: string,
     *     plan: array{
     *       key: string,
     *       name: string,
     *       version: int,
     *       currency: string,
     *       monthly_amount: int|null,
     *       annual_amount: int|null,
     *       quote_required: bool
     *     },
     *     last_changed_at: string
     *   }
     * }|array{status: 'not_configured', subscription: null}
     */
    public function forTenant(string $tenantId): array
    {
        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);

        if (!$subscription instanceof Subscription) {
            return [
                'status' => 'not_configured',
                'subscription' => null,
            ];
        }

        $planVersion = $subscription->planVersion();
        $plan = $planVersion->plan();

        return [
            'status' => 'configured',
            'subscription' => [
                'state' => $subscription->state()->value,
                'plan' => [
                    'key' => $plan->key(),
                    'name' => $plan->name(),
                    'version' => $planVersion->version(),
                    'currency' => $planVersion->currency(),
                    'monthly_amount' => $planVersion->monthlyAmount(),
                    'annual_amount' => $planVersion->annualAmount(),
                    'quote_required' => $planVersion->quoteRequired(),
                ],
                'last_changed_at' => $subscription
                    ->lastChangedAt()
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\\TH:i:s.u\\Z'),
            ],
        ];
    }
}
