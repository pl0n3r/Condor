<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\PlanConfiguratorCatalogReader;
use App\Application\Commercial\PlanQuoteService;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanQuoteTest extends KernelTestCase
{
    private PlanQuoteService $service;
    private EntityManagerInterface $entityManager;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        (new CommercialCatalogSeeder($manager))->seed();
        $catalog = new CommercialCatalogReader($manager, new PlanVersionTimeline());
        $this->service = new PlanQuoteService(
            new PlanConfiguratorCatalogReader($catalog, $manager),
            $manager,
        );
        $this->at = new DateTimeImmutable('2026-09-27T12:00:00Z');
    }

    public function testQuoteUsesEffectiveCatalogAndServerSideQuantities(): void
    {
        $quote = $this->service->quote(
            'business', 'commerce', 'monthly',
            ['users' => 12, 'locations' => 3, 'companies' => 1],
            [], $this->at,
        );
        self::assertSame(199900 + (2 * 14900), $quote->totalAmount());
        self::assertSame(['extra-user'], $quote->addOns());
    }

    public function testClientSuppliedTotalIsIgnored(): void
    {
        $quote = $this->service->quote(
            'basic', 'commerce', 'monthly',
            ['users' => 3, 'locations' => 1, 'companies' => 1],
            [], $this->at, 1,
        );
        self::assertSame(79900, $quote->totalAmount());
    }

    public function testIncompatibleSelectionFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        $this->service->quote(
            'pro', 'legal', 'monthly',
            ['users' => 30, 'locations' => 10, 'companies' => 5],
            ['production-lite'], $this->at,
        );
    }

    public function testUnpricedConfigurationBecomesProposal(): void
    {
        $quote = $this->service->quote(
            'enterprise', 'legal', 'monthly',
            ['users' => 1, 'locations' => 1, 'companies' => 1],
            [], $this->at,
        );
        self::assertTrue($quote->proposalRequired());
        self::assertNull($quote->totalAmount());

        $annualWithExtra = $this->service->quote(
            'business', 'commerce', 'annual',
            ['users' => 11, 'locations' => 3, 'companies' => 1],
            [], $this->at,
        );
        self::assertTrue($annualWithExtra->proposalRequired());
    }

    public function testQuotePreservesPlanVersionAndComposition(): void
    {
        $quote = $this->service->quote(
            'business', 'legal', 'monthly',
            ['users' => 10, 'locations' => 3, 'companies' => 1],
            ['production-lite'], $this->at,
        );
        self::assertSame('business', $quote->planVersion()->plan()->key());
        self::assertSame(1, $quote->planVersion()->version());
        self::assertSame('legal', $quote->vertical()->key());
        self::assertSame(['production-lite'], $quote->addOns());
        self::assertSame('draft', $quote->status());
        self::assertSame(299800, $quote->totalAmount());
        self::assertInstanceOf(Quote::class, $this->entityManager->find(Quote::class, $quote->id()));
    }

    public function testSameVersionAndConfigurationIsDeterministic(): void
    {
        $input = ['users' => 3, 'locations' => 1, 'companies' => 1];
        $a = $this->service->quote('basic', 'commerce', 'annual', $input, [], $this->at);
        $b = $this->service->quote('basic', 'commerce', 'annual', $input, [], $this->at);
        self::assertSame($a->totalAmount(), $b->totalAmount());
        self::assertSame(799000, $a->totalAmount());
        self::assertSame($a->quantities(), $b->quantities());
    }
}
