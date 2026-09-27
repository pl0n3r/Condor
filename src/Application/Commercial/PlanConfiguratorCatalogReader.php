<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\Entity\VerticalCapability;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlanConfiguratorCatalogReader
{
    public function __construct(
        private CommercialCatalogReader $catalog,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string,mixed> */
    public function options(
        string $planKey,
        string $verticalKey,
        DateTimeImmutable $at,
    ): array {
        $planKey = strtolower(trim($planKey));
        $verticalKey = strtolower(trim($verticalKey));

        $vertical = $this->entityManager
            ->getRepository(Vertical::class)
            ->findOneBy(['key' => $verticalKey, 'active' => true]);
        if (!$vertical instanceof Vertical) {
            throw new DomainException('Vertical comercial desconocido.');
        }

        $plan = $this->plan($planKey, $at);
        if (!in_array($vertical->key(), $plan['verticals'], true)) {
            throw new DomainException('El vertical no es compatible con la PlanVersion vigente.');
        }

        $allowedCapabilities = array_fill_keys($plan['capabilities'], true);
        $relations = $this->entityManager
            ->getRepository(VerticalCapability::class)
            ->findBy(['vertical' => $vertical], ['priority' => 'ASC', 'key' => 'ASC']);

        $capabilities = [];
        foreach ($relations as $relation) {
            if (!$relation instanceof VerticalCapability) {
                continue;
            }
            $capability = $relation->capability();
            if (!$capability->isActive() || !isset($allowedCapabilities[$capability->key()])) {
                continue;
            }
            $capabilities[] = [
                'key' => $capability->key(),
                'name' => $capability->name(),
                'priority' => $relation->priority(),
            ];
        }

        return [
            'plan' => [
                'key' => $plan['key'],
                'name' => $plan['name'],
                'version' => $plan['version'],
                'currency' => $plan['currency'],
                'monthly_amount' => $plan['monthly_amount'],
                'annual_amount' => $plan['annual_amount'],
                'quote_required' => $plan['quote_required'],
            ],
            'vertical' => [
                'key' => $vertical->key(),
                'name' => $vertical->name(),
            ],
            'limits' => $plan['limits'],
            'capabilities' => $capabilities,
            'addons' => $plan['addons'],
        ];
    }

    /** @return array<string,mixed> */
    private function plan(string $planKey, DateTimeImmutable $at): array
    {
        foreach ($this->catalog->current($at) as $plan) {
            if ($plan['key'] === $planKey) {
                return $plan;
            }
        }

        throw new DomainException('Plan comercial vigente desconocido.');
    }
}
