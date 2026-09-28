<?php

declare(strict_types=1);

namespace App\Tests\Domain\Market;

final class MarketFixture
{
    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    public static function market(array $overrides = []): array
    {
        return array_replace([
            'market_id' => 'market-co',
            'tenant_id' => 'tenant-condor',
            'venture_id' => 'condor',
            'country_code' => 'CO',
            'status' => 'preparing',
            'priority' => 'primary',
            'locales' => ['es-CO'],
            'currencies' => ['COP'],
            'legal_entity_ref' => 'legal-entity:condor-co',
            'lex_assessment_ref' => 'lex:assessment-co-v1',
            'infrastructure_ref' => 'infra:region-primary',
            'timezone' => 'America/Bogota',
            'source_ref' => 'condor:market/co',
            'observed_at' => 1790614800,
            'freshness' => 'fresh',
        ], $overrides);
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, array<string, mixed>>
     */
    public static function gates(array $overrides = []): array
    {
        $gates = [];
        foreach ([
            'product',
            'lex',
            'privacy',
            'localization',
            'currency_pricing',
            'payments_billing',
            'support_knowledge',
            'infrastructure',
            'security',
            'analytics',
            'capital',
        ] as $name) {
            $gates[$name] = self::gate(
                'SATISFIED',
                ['evidence:'.$name],
            );
        }

        return array_replace($gates, $overrides);
    }

    /** @param list<string> $evidence @return array<string, mixed> */
    public static function gate(
        string $status,
        array $evidence,
        string $freshness = 'FRESH',
    ): array {
        return [
            'status' => $status,
            'evidence_refs' => $evidence,
            'freshness' => $freshness,
        ];
    }
}
