<?php
declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\Entity\Vertical;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlanQuoteService
{
    public function __construct(
        private PlanConfiguratorCatalogReader $catalog,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string,int> $quantities
     * @param list<string> $addOnKeys
     */
    public function quote(
        string $planKey,
        string $verticalKey,
        string $cycle,
        array $quantities,
        array $addOnKeys,
        DateTimeImmutable $at,
        ?int $clientSuppliedTotal = null,
    ): Quote {
        return $this->calculate(
            $planKey,
            $verticalKey,
            $cycle,
            $quantities,
            $addOnKeys,
            $at,
            $clientSuppliedTotal,
            true,
        );
    }

    /**
     * @param array<string,int> $quantities
     * @param list<string> $addOnKeys
     */
    public function preview(
        string $planKey,
        string $verticalKey,
        string $cycle,
        array $quantities,
        array $addOnKeys,
        DateTimeImmutable $at,
        ?int $clientSuppliedTotal = null,
    ): Quote {
        return $this->calculate(
            $planKey,
            $verticalKey,
            $cycle,
            $quantities,
            $addOnKeys,
            $at,
            $clientSuppliedTotal,
            false,
        );
    }

    /**
     * @param array<string,int> $quantities
     * @param list<string> $addOnKeys
     */
    private function calculate(
        string $planKey,
        string $verticalKey,
        string $cycle,
        array $quantities,
        array $addOnKeys,
        DateTimeImmutable $at,
        ?int $clientSuppliedTotal,
        bool $persist,
    ): Quote {
        unset($clientSuppliedTotal);
        if (!in_array($cycle, ['monthly', 'annual'], true)) {
            throw new DomainException('Ciclo comercial inválido.');
        }

        $options = $this->catalog->options($planKey, $verticalKey, $at);
        $allowed = [];
        foreach ($options['addons'] as $addOn) {
            $allowed[$addOn['key']] = $addOn;
        }

        $quantities = $this->normalizeQuantities($quantities, $options['limits']);
        $selected = array_values(array_unique(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            $addOnKeys,
        )));
        foreach ($selected as $key) {
            if (in_array($key, PlanConfigurationRules::SCALE_ADDONS, true)) {
                throw new DomainException('Los extras de escala se derivan de cantidades.');
            }
            if (!isset($allowed[$key])) {
                throw new DomainException('Add-on incompatible con la PlanVersion.');
            }
        }

        $base = $cycle === 'monthly'
            ? $options['plan']['monthly_amount']
            : $options['plan']['annual_amount'];

        if ($options['plan']['quote_required'] || !is_int($base)) {
            return $this->finish(
                $options,
                $cycle,
                $quantities,
                $selected,
                null,
                null,
                true,
                $at,
                $persist,
            );
        }

        [$extraAmount, $derived] = $this->scaleExtras(
            $quantities,
            $options['limits'],
            $allowed,
        );
        foreach ($selected as $key) {
            $price = $allowed[$key]['monthly_amount'];
            if (!is_int($price) || $price < 1) {
                return $this->finish(
                    $options,
                    $cycle,
                    $quantities,
                    [...$derived, ...$selected],
                    null,
                    null,
                    true,
                    $at,
                    $persist,
                );
            }
            $extraAmount += $price;
        }

        $proposal = $cycle === 'annual' && $extraAmount > 0;

        return $this->finish(
            $options,
            $cycle,
            $quantities,
            [...$derived, ...$selected],
            $base,
            $proposal ? null : $base + $extraAmount,
            $proposal,
            $at,
            $persist,
            $proposal ? null : $extraAmount,
        );
    }

    /**
     * @param array<string,int> $input
     * @param array<string,mixed> $limits
     * @return array<string,int>
     */
    private function normalizeQuantities(array $input, array $limits): array
    {
        foreach ($input as $key => $_) {
            if (!array_key_exists($key, PlanConfigurationRules::SCALE_ADDONS)) {
                throw new DomainException('Cantidad comercial desconocida.');
            }
        }
        $result = [];
        foreach (PlanConfigurationRules::SCALE_ADDONS as $key => $_) {
            $value = $input[$key] ?? $limits[$key] ?? null;
            if (!is_int($value) || $value < 1) {
                throw new DomainException('Cantidad comercial inválida.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @param array<string,int> $quantities
     * @param array<string,mixed> $limits
     * @param array<string,array<string,mixed>> $allowed
     * @return array{int,list<string>}
     */
    private function scaleExtras(array $quantities, array $limits, array $allowed): array
    {
        $amount = 0;
        $keys = [];
        foreach (PlanConfigurationRules::SCALE_ADDONS as $quantityKey => $addOnKey) {
            $units = $quantities[$quantityKey] - (int) ($limits[$quantityKey] ?? 0);
            if ($units <= 0) {
                continue;
            }
            $price = $allowed[$addOnKey]['monthly_amount'] ?? null;
            if (!is_int($price) || $price < 1) {
                throw new DomainException('La cantidad excede el catálogo permitido.');
            }
            $amount += $units * $price;
            $keys[] = $addOnKey;
        }
        return [$amount, $keys];
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,int> $quantities
     * @param list<string> $addOns
     */
    private function finish(
        array $options,
        string $cycle,
        array $quantities,
        array $addOns,
        ?int $base,
        ?int $total,
        bool $proposal,
        DateTimeImmutable $at,
        bool $persist,
        ?int $extras = null,
    ): Quote {
        $plan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['key' => $options['plan']['key']]);
        $vertical = $this->entityManager->getRepository(Vertical::class)
            ->findOneBy(['key' => $options['vertical']['key']]);
        if (!$plan instanceof Plan || !$vertical instanceof Vertical) {
            throw new DomainException('Catálogo comercial inconsistente.');
        }
        $version = $this->entityManager->getRepository(PlanVersion::class)
            ->findOneBy(['plan' => $plan, 'version' => $options['plan']['version']]);
        if (!$version instanceof PlanVersion) {
            throw new DomainException('PlanVersion comercial no disponible.');
        }

        $quote = new Quote(
            $version,
            $vertical,
            $cycle,
            $quantities,
            array_values(array_unique($addOns)),
            $base,
            $extras,
            $total,
            $proposal,
            $at,
        );
        if ($persist) {
            $this->entityManager->persist($quote);
            $this->entityManager->flush();
        }

        return $quote;
    }
}
