<?php

declare(strict_types=1);

namespace App\Tests\Domain\Market;

use App\Application\Market\MarketReadiness;
use App\Domain\Market\Market;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MarketTest extends TestCase
{
    public function testMarketKeepsBusinessAndJurisdictionDimensionsSeparate(): void
    {
        $market = Market::fromArray($this->marketPayload());
        $snapshot = $market->snapshot();

        self::assertSame('CO', $snapshot['country_code']);
        self::assertSame(['es-CO'], $snapshot['locales']);
        self::assertSame(['COP'], $snapshot['currencies']);
        self::assertSame('legal-entity:condor-co', $snapshot['legal_entity_ref']);
        self::assertSame('lex:assessment-co-v1', $snapshot['lex_assessment_ref']);
        self::assertSame('infra:region-primary', $snapshot['infrastructure_ref']);
        self::assertSame('America/Bogota', $snapshot['timezone']);
    }

    public function testLaunchStatesRequireExplicitReadinessAndLexGates(): void
    {
        $preparing = Market::fromArray($this->marketPayload(['status' => 'preparing']));

        try {
            $preparing->transitionTo('launch_ready');
            self::fail('launch_ready sin readiness debe fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $blocked = MarketReadiness::evaluate(
            $preparing,
            $this->gates(['lex' => $this->gate('UNKNOWN', ['lex:pending'])]),
        );
        self::assertFalse($blocked['launch_allowed']);

        $this->expectException(DomainException::class);
        $preparing->transitionTo('launch_ready', $blocked);
    }

    public function testLaunchReadyAndLiveRequireFreshEvidence(): void
    {
        $preparing = Market::fromArray($this->marketPayload(['status' => 'preparing']));
        $ready = MarketReadiness::evaluate($preparing, $this->gates());
        self::assertTrue($ready['launch_allowed']);

        $launchReady = $preparing->transitionTo('launch_ready', $ready);
        self::assertSame('launch_ready', $launchReady->status());

        $liveEvidence = MarketReadiness::evaluate($launchReady, $this->gates());
        $live = $launchReady->transitionTo('live', $liveEvidence);
        self::assertSame('live', $live->status());
    }

    public function testMarketsRemainIsolatedByTenantAndVenture(): void
    {
        $a = Market::fromArray($this->marketPayload([
            'market_id' => 'market-co-a',
            'tenant_id' => 'tenant-a',
            'venture_id' => 'condor',
        ]));
        $b = Market::fromArray($this->marketPayload([
            'market_id' => 'market-co-b',
            'tenant_id' => 'tenant-b',
            'venture_id' => 'condor',
        ]));
        $c = Market::fromArray($this->marketPayload([
            'market_id' => 'market-co-c',
            'tenant_id' => 'tenant-a',
            'venture_id' => 'other-venture',
        ]));

        self::assertNotSame($a->contextKey(), $b->contextKey());
        self::assertNotSame($a->contextKey(), $c->contextKey());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function marketPayload(array $overrides = []): array
    {
        return array_replace([
            'market_id' => 'market-co',
            'tenant_id' => 'tenant-condor',
            'venture_id' => 'condor',
            'country_code' => 'CO',
            'status' => 'validating',
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
    private function gates(array $overrides = []): array
    {
        $names = [
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
        ];
        $gates = [];
        foreach ($names as $name) {
            $gates[$name] = $this->gate('SATISFIED', ['evidence:'.$name]);
        }

        return array_replace($gates, $overrides);
    }

    /** @param list<string> $evidence */
    private function gate(string $status, array $evidence, string $freshness = 'FRESH'): array
    {
        return [
            'status' => $status,
            'evidence_refs' => $evidence,
            'freshness' => $freshness,
        ];
    }
}
