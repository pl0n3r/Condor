<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CommercialCatalogReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanVersionTimeline $timeline,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function current(DateTimeImmutable $at): array
    {
        $versions = $this->entityManager
            ->getRepository(PlanVersion::class)
            ->findAll();
        $plans = $this->entityManager
            ->getRepository(Plan::class)
            ->findBy(['active' => true]);

        $result = [];
        foreach ($plans as $plan) {
            $version = $this->timeline->effectiveAt($versions, $plan, $at);
            if (!$version instanceof PlanVersion) {
                continue;
            }
            $result[] = [
                'plan_version_id' => $version->id(),
                'key' => $plan->key(),
                'name' => $plan->name(),
                'version' => $version->version(),
                'currency' => $version->currency(),
                'monthly_amount' => $version->monthlyAmount(),
                'annual_amount' => $version->annualAmount(),
                'quote_required' => $version->quoteRequired(),
                'limits' => $version->limits(),
                'vertical_prices' => $version->priceOptions()['verticals'] ?? [],
                'verticals' => array_map(
                    static fn (Vertical $item): string => $item->key(),
                    $version->verticals(),
                ),
                'capabilities' => array_map(
                    static fn (Capability $item): string => $item->key(),
                    $version->capabilities(),
                ),
                'addons' => array_map(
                    static fn (AddOn $item): array => [
                        'key' => $item->key(),
                        'name' => $item->name(),
                        'currency' => $version->currency(),
                        'monthly_amount' => ($version->priceOptions()['addons'][$item->key()] ?? ['monthly_amount' => $item->monthlyAmount()])['monthly_amount'],
                        'annual_amount' => ($version->priceOptions()['addons'][$item->key()] ?? ['annual_amount' => null])['annual_amount'],
                        'quote_required' => ($version->priceOptions()['addons'][$item->key()] ?? ['quote_required' => $item->quoteRequired()])['quote_required'],
                    ],
                    $version->addOns(),
                ),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);
        return $result;
    }
}
