<?php

declare(strict_types=1);

namespace App\Tests\Domain\CommercialCatalog;

use App\Application\CommercialCatalog\CommercialCatalogReader;
use App\Domain\CommercialCatalog\CommercialCatalogRepository;
use App\Domain\CommercialCatalog\Entity\AddOn;
use App\Domain\CommercialCatalog\Entity\Capability;
use App\Domain\CommercialCatalog\Entity\Plan;
use App\Domain\CommercialCatalog\Entity\PlanVersion;
use App\Domain\CommercialCatalog\Entity\Vertical;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CommercialCatalogTest extends TestCase
{
    public function testPlanVersionsCanCoexistAndResolveByDate(): void
    {
        $plan = new Plan('business', 'Negocio');
        $v1 = new PlanVersion(
            $plan,
            1,
            PlanVersion::PRICING_FIXED,
            199900,
            1999000,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );
        $v2 = new PlanVersion(
            $plan,
            2,
            PlanVersion::PRICING_FIXED,
            219900,
            2199000,
            new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        self::assertTrue($v1->isEffectiveAt(new DateTimeImmutable('2026-06-01T00:00:00+00:00')));
        self::assertFalse($v1->isEffectiveAt(new DateTimeImmutable('2027-01-01T00:00:00+00:00')));
        self::assertTrue($v2->isEffectiveAt(new DateTimeImmutable('2027-01-01T00:00:00+00:00')));
    }

    public function testPlanVersionSupportsIndependentVerticalsAndStableKeys(): void
    {
        $version = new PlanVersion(
            new Plan('business', 'Negocio'),
            1,
            PlanVersion::PRICING_FIXED,
            199900,
            1999000,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $legal = new Vertical('legal', 'Legal / abogados');
        $commerce = new Vertical('commerce-distribution-ecommerce', 'Comercio');
        $capability = new Capability('custom-domain', 'Dominio propio');
        $productionLite = new AddOn(
            'production-lite',
            'Producción Lite',
            AddOn::PRICING_FIXED,
            99900,
        );

        $version->addVertical($legal);
        $version->addVertical($commerce);
        $version->addCapability($capability);
        $version->addAddOn($productionLite);

        self::assertSame(
            ['legal', 'commerce-distribution-ecommerce'],
            array_map(
                static fn (Vertical $item): string => $item->key(),
                $version->verticals(),
            ),
        );
        self::assertSame('custom-domain', $version->capabilities()[0]->key());
        self::assertSame('production-lite', $version->addOns()[0]->key());
        self::assertSame(99900, $productionLite->monthlyPriceCop());
    }

    public function testReaderDelegatesCurrentResolutionWithoutPlanNameSwitches(): void
    {
        $at = new DateTimeImmutable('2026-10-01T00:00:00+00:00');
        $version = new PlanVersion(
            new Plan('stable-key', 'Nombre visible variable'),
            1,
            PlanVersion::PRICING_FIXED,
            100,
            1000,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $repository = new class($version) implements CommercialCatalogRepository {
            public function __construct(private readonly PlanVersion $version)
            {
            }

            public function effectivePlanVersions(DateTimeImmutable $at): array
            {
                return [$this->version];
            }

            public function activeVerticals(): array
            {
                return [new Vertical('legal', 'Legal')];
            }

            public function activeCapabilities(): array
            {
                return [new Capability('catalog.core', 'Catálogo')];
            }

            public function activeAddOns(): array
            {
                return [
                    new AddOn(
                        'production-lite',
                        'Producción Lite',
                        AddOn::PRICING_FIXED,
                        99900,
                    ),
                ];
            }
        };

        $snapshot = (new CommercialCatalogReader($repository))->current($at);

        self::assertSame($at, $snapshot->effectiveAt());
        self::assertSame('stable-key', $snapshot->planVersions()[0]->plan()->key());
        self::assertSame('legal', $snapshot->verticals()[0]->key());
        self::assertSame('production-lite', $snapshot->addOns()[0]->key());
    }
}
