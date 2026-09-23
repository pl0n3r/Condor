<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\VariantPrice;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class EffectivePriceResolver
{
    /** @param list<PriceRule> $rules */
    public function resolve(
        VariantPrice $price,
        ?CommercialCategory $category,
        array $rules,
        ?DateTimeImmutable $at = null,
    ): EffectivePrice {
        $tenantId = $price->tenant()->id();
        $listId = $price->priceList()->id();

        if ($category !== null && $category->tenant()->id() !== $tenantId) {
            throw new DomainException('La categoría comercial no pertenece al tenant del precio.');
        }

        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $applicable = [];
        foreach ($rules as $rule) {
            if (
                $rule->tenant()->id() !== $tenantId
                || $rule->priceList()->id() !== $listId
                || !$rule->appliesTo($category, $at)
            ) {
                continue;
            }
            $applicable[] = $rule;
        }

        usort(
            $applicable,
            static fn (PriceRule $left, PriceRule $right): int => (
                ($right->priority() <=> $left->priority())
                ?: ($right->specificity() <=> $left->specificity())
                ?: strcmp($left->id(), $right->id())
            ),
        );

        $winner = $applicable[0] ?? null;
        $base = $price->amountMinor();

        return new EffectivePrice(
            $base,
            $winner instanceof PriceRule ? $winner->applyTo($base) : $base,
            $price->priceList()->currency(),
            $listId,
            $winner?->id(),
        );
    }
}
