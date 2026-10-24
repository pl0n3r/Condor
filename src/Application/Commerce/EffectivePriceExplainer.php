<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Read-only explanation of the existing single-winner pricing policy.
 *
 * PriceRule has no channel or volume predicate: the channel selects its
 * configured price list, and quantity scales the unit price only.
 */
final readonly class EffectivePriceExplainer
{
    private const MAX_SAFE_MINOR = 9007199254740991;
    private const MAX_QUANTITY = 1000;

    public function __construct(
        private PricingService $pricing,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function explain(
        Tenant $tenant,
        ProductVariant $variant,
        SalesChannel $channel,
        int $quantity,
        ?DateTimeImmutable $at = null,
    ): array {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new DomainException('La cantidad debe estar entre 1 y 1000.');
        }
        if (
            $channel->tenant()->id() !== $tenant->id()
            || !$channel->isPublishable()
            || $channel->priceList()->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException('El canal no está disponible en este tenant.');
        }
        if (
            $variant->tenant()->id() !== $tenant->id()
            || !$variant->isActive()
            || !$variant->product()->isActive()
        ) {
            throw new DomainException('La variante no está disponible en este tenant.');
        }

        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        $list = $channel->priceList();

        // PricingService / EffectivePriceResolver remain the sole authority
        // for discount amount, precedence and selected rule.
        $effective = $this->pricing->resolve($tenant, $variant, $list, null, $at);
        if ($effective->effectiveAmountMinor > intdiv(self::MAX_SAFE_MINOR, $quantity)) {
            throw new DomainException('El importe total supera el rango monetario seguro.');
        }

        $rows = $this->entityManager->getRepository(PriceRule::class)->findBy([
            'tenant' => $tenant,
            'priceList' => $list,
        ]);
        $rules = [];
        foreach ($rows as $rule) {
            if (
                $rule->tenant()->id() === $tenant->id()
                && $rule->priceList()->id() === $list->id()
            ) {
                $rules[] = $rule;
            }
        }
        usort(
            $rules,
            static fn (PriceRule $a, PriceRule $b): int => (
                ($b->priority() <=> $a->priority())
                ?: ($b->specificity() <=> $a->specificity())
                ?: strcmp($a->id(), $b->id())
            ),
        );

        $explanation = [];
        foreach ($rules as $rule) {
            $eligible = $rule->appliesTo(null, $at);
            $applied = $eligible && $rule->id() === $effective->ruleId;
            $reason = match (true) {
                !$rule->isActive() => 'inactive',
                $rule->validFrom() !== null && $at < $rule->validFrom() => 'not_yet_valid',
                $rule->validUntil() !== null && $at > $rule->validUntil() => 'expired',
                $rule->commercialCategory() !== null => 'customer_category_required',
                $applied => 'selected',
                $eligible => 'lower_precedence',
                default => 'not_applicable',
            };
            $explanation[] = [
                'rule_id' => $rule->id(),
                'name' => $rule->name(),
                'priority' => $rule->priority(),
                'specificity' => $rule->specificity(),
                'valid_from' => $rule->validFrom()?->format(DATE_ATOM),
                'valid_until' => $rule->validUntil()?->format(DATE_ATOM),
                'discount_type' => $rule->discountType(),
                'discount_value' => $rule->discountValue(),
                'eligible' => $eligible,
                'applied' => $applied,
                'reason' => $reason,
            ];
        }

        return [
            'variant_id' => $variant->id(),
            'channel_id' => $channel->id(),
            'price_list_id' => $effective->priceListId,
            'currency' => $effective->currency,
            'quantity' => $quantity,
            'evaluated_at' => $at->format(DATE_ATOM),
            'base_unit_amount_minor' => $effective->baseAmountMinor,
            'unit_amount_minor' => $effective->effectiveAmountMinor,
            'total_amount_minor' => $effective->effectiveAmountMinor * $quantity,
            'selected_rule_id' => $effective->ruleId,
            'precedence' => ['priority_desc', 'specificity_desc', 'rule_id_asc'],
            'quantity_policy' => 'unit_price_times_quantity_no_volume_rules',
            'rules' => $explanation,
        ];
    }
}
