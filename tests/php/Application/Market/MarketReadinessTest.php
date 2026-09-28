<?php

declare(strict_types=1);

namespace App\Tests\Application\Market;

use App\Application\Market\MarketReadiness;
use App\Domain\Market\Market;
use PHPUnit\Framework\TestCase;

final class MarketReadinessTest extends TestCase
{
    public function testReadinessReturnsConcreteEvidenceBackedGatesWithoutOpaqueScore(): void
    {
        $market = Market::fromArray($this->marketPayload());
        $gates = $this->gates([
            'payments_billing' => $this->gate(
                'NOT_APPLICABLE',
                ['decision:payments-not-required'],
            ),
        ]);

        $result = MarketReadiness::evaluate($market, $gates);

        self::assertTrue($result['launch_allowed']);
        self::assertSame('FRESH', $result['evidence_freshness']);
        self::assertSame('SATISFIED', $result['lex_status']);
        self::assertSame([], $result['blockers']);
        self::assertArrayNotHasKey('score', $result);
        self::assertSame(
            'NOT_APPLICABLE',
            $result['gates']['payments_billing']['status'],
        );
        self::assertSame(
            ['decision:payments-not-required'],
            $result['gates']['payments_billing']['evidence_refs'],
        );
    }

    public function testUnknownStaleOrMissingEvidenceBlocksLaunch(): void
    {
        $market = Market::fromArray($this->marketPayload());
        $result = MarketReadiness::evaluate($market, $this->gates([
            'lex' => $this->gate('UNKNOWN', ['lex:pending']),
            'security' => $this->gate('SATISFIED', ['security:audit'], 'STALE'),
            'analytics' => $this->gate('SATISFIED', []),
        ]));

        self::assertFalse($result['launch_allowed']);
        self::assertSame('DEGRADED', $result['evidence_freshness']);
        self::assertSame(
            ['lex', 'security', 'analytics'],
            array_column($result['blockers'], 'gate'),
        );
    }

    public function testReadinessGapsMapToFactoryWorkItemsWithoutParallelQueue(): void
    {
        $market = Market::fromArray($this->marketPayload());
        $result = MarketReadiness::evaluate($market, $this->gates([
            'lex' => $this->gate('GAP', ['lex:gap-country-pack']),
            'localization' => $this->gate('UNKNOWN', ['i18n:review-pending']),
            'security' => $this->gate('SATISFIED', ['security:audit'], 'STALE'),
        ]));

        $items = MarketReadiness::toFactoryWorkItems($result, [
            'group_id' => 'pl0n3r-group',
            'project_id' => 'condor',
            'repository_ref' => 'pl0n3r/Condor',
            'producer_ref' => 'condor:market-readiness',
            'authority_level' => 'operational',
            'policy_ref' => 'factory:queue-v1',
        ]);

        self::assertSame(
            ['compliance_review', 'knowledge_documentation', 'security'],
            array_column($items, 'work_type'),
        );

        foreach ($items as $item) {
            self::assertSame('automatic', $item['origin_mode']);
            self::assertSame('product', $item['origin_system']);
            self::assertSame('factory:queue-v1', $item['policy_ref']);
            self::assertArrayNotHasKey('provider', $item);
            self::assertArrayNotHasKey('executor', $item);
            self::assertArrayNotHasKey('scheduler', $item);
            self::assertNotSame([], $item['evidence_refs']);
        }
    }

    /** @return array<string, mixed> */
    private function marketPayload(): array
    {
        return [
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
        ];
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
