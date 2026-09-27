<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\Entity\Vertical;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PlanQuoteService
{
    private const EXTRA_ADDON = [
        'users' => 'extra-user',
        'locations' => 'extra-location',
        'companies' => 'extra-company',
    ];

    public function __construct(
        private PlanConfiguratorCatalogReader $catalog,
        private EntityManagerInterface $entityManager,
    ) {
    }

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
        unset($clientSuppliedTotal);

        $options = $this->catalog->options($planKey, $verticalKey, $at);
        if (!in_array($cycle, ['monthly', 'annual'], true)) {
            throw new DomainException('Ciclo comercial inválido.');
        }

        $allowedAddOns = [];
        foreach ($options['addons'] as $addOn) {
            $allowedAddOns[$addOn['key']] = $addOn;
        }

        $normalizedQuantities = $this->quantities($quantities, $options['limits']);
        $selected = array_values(array_unique(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            $addOnKeys,
        )));
        foreach ($selected as $key) {
            if (!isset($allowedAddOns[$key])) {
                throw new DomainException('Add-on incompatible con la PlanVersion.');
            }
        }

        $extraAmount = 0;
        $extraKeys = $selected;
        foreach (self::EXTRA_ADDON as $quantityKey => $addOnKey) {
            $extraUnits = $normalizedQuantities[$quantityKey]
                - (int) ($options['limits'][$quantityKey] ?? 0);
            if ($extraUnits <= 0) {
                continue;
            }
            if (!isset($allowedAddOns[$addOnKey])) {
                throw new DomainException('La cantidad excede el catálogo permitido.');
            }
            $price = $allowedAddOns[$addOnKey]['monthly_amount'];
            if (!is_int($price) || $price < 1) {
                throw new DomainException('El extra requiere propuesta comercial.');
            }
            $extraAmount += $extraUnits * $price;
            $extraKeys[] = $addOnKey;
        }

        foreach ($selected as $key) {
            $price = $allowedAddOns[$key]['monthly_amount'];
            if (!is_int($price) || $price < 1) {
                return $this->persistProposal($options, $cycle, $normalizedQuantities, $extraKeys, $at);
            }
            $extraAmount += $price;
        }

        $base = $cycle === 'monthly'
            ? $options['plan']['monthly_amount']
            : $options['plan']['annual_amount'];

        $requiresProposal = $options['plan']['quote_required']
            || !is_int($base)
            || ($cycle === 'annual' && $extraAmount > 0);

        if ($requiresProposal) {
            return $this->persistProposal($options, $cycle, $normalizedQuantities, $extraKeys, $at);
        }

        return $this->persist(
            $options,
            $cycle,
            $normalizedQuantities,
            $extraKeys,
            $base,
            $extraAmount,
            $base + $extraAmount,
            false,
            $at,
        );
    }

    /**
     * @param array<string,int> $input
     * @param array<string,mixed> $limits
     * @return array<string,int>
     */
    private function quantities(array $input, array $limits): array
    {
        $result = [];
        foreach (self::EXTRA_ADDON as $key => $_) {
            $value = $input[$key] ?? $limits[$key] ?? 0;
            if (!is_int($value) || $value < 1) {
                throw new DomainException('Cantidad comercial inválida.');
            }
            $result[$key] = $value;
        }
        foreach ($input as $key => $_) {
            if (!array_key_exists($key, self::EXTRA_ADDON)) {
                throw new DomainException('Cantidad comercial desconocida.');
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,int> $quantities
     * @param list<string> $addOns
     */
    private function persistProposal(
        array $options,
        string $cycle,
        array $quantities,
        array $addOns,
        DateTimeImmutable $at,
    ): Quote {
        return $this->persist(
            $options, $cycle, $quantities, $addOns,
            null, null, null, true, $at,
        );
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,int> $quantities
     * @param list<string> $addOns
     */
    private function persist(
        array $options,
        string $cycle,
        array $quantities,
        array $addOns,
        ?int $base,
        ?int $extras,
        ?int $total,
        bool $proposalRequired,
        DateTimeImmutable $at,
    ): Quote {
        $planVersion = $this->entityManager->getRepository(PlanVersion::class)->findOneBy([
            'version' => $options['plan']['version'],
            'plan' => $this->entityManager->getRepository(\App\Domain\Commercial\Entity\Plan::class)
                ->findOneBy(['key' => $options['plan']['key']]),
        ]);
        $vertical = $this->entityManager->getRepository(Vertical::class)
            ->findOneBy(['key' => $options['vertical']['key']]);

        if (!$planVersion instanceof PlanVersion || !$vertical instanceof Vertical) {
            throw new DomainException('No fue posible fijar la versión comercial de la cotización.');
        }

        $quote = new Quote(
            $planVersion,
            $vertical,
            $cycle,
            $quantities,
            $addOns,
            $base,
            $extras,
            $total,
            $proposalRequired,
            $at->add(new DateInterval('P30D')),
            $at,
        );
        $this->entityManager->persist($quote);
        $this->entityManager->flush();

        return $quote;
    }
}
