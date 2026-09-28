<?php

declare(strict_types=1);

namespace App\Tests\Application\Market;

use App\Application\Market\MarketReadiness;
use App\Domain\Market\Market;
use App\Tests\Domain\Market\MarketFixture;
use PHPUnit\Framework\TestCase;

final class MarketReadinessTest extends TestCase
{
    public function testReadinessReturnsConcreteEvidenceBackedGatesWithoutOpaqueScore(): void
    {
        $market = Market::fromArray(MarketFixture::market());
        $gates = MarketFixture::gates([
            'payments_billing' => MarketFixture::gate(
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
        $result = MarketReadiness::evaluate($market, MarketFixture::gates([
            'lex' => MarketFixture::gate('UNKNOWN', ['lex:pending']),
            'security' => MarketFixture::gate('SATISFIED', ['security:audit'], 'STALE'),
            'analytics' => MarketFixture::gate('SATISFIED', []),
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
        $result = MarketReadiness::evaluate($market, MarketFixture::gates([
            'lex' => MarketFixture::gate('GAP', ['lex:gap-country-pack']),
            'localization' => MarketFixture::gate('UNKNOWN', ['i18n:review-pending']),
            'security' => MarketFixture::gate('SATISFIED', ['security:audit'], 'STALE'),
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

}
