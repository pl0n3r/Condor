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
    private EntityManagerInterface $manager;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
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
        $q = $this->service->quote('business', 'commerce', 'monthly',
            ['users'=>12,'locations'=>3,'companies'=>1], [], $this->at);
        self::assertSame(229700, $q->totalAmount());
        self::assertSame(['extra-user'], $q->addOns());
    }

    public function testClientSuppliedTotalIsIgnored(): void
    {
        $q = $this->service->quote('basic', 'commerce', 'monthly',
            ['users'=>3,'locations'=>1,'companies'=>1], [], $this->at, 1);
        self::assertSame(79900, $q->totalAmount());
    }

    public function testIncompatibleSelectionFailsClosed(): void
    {
        $this->expectException(DomainException::class);
        $this->service->quote('pro', 'legal', 'monthly',
            ['users'=>30,'locations'=>10,'companies'=>5], ['production-lite'], $this->at);
    }

    public function testUnpricedConfigurationBecomesProposal(): void
    {
        $enterprise = $this->service->quote('enterprise', 'legal', 'monthly',
            ['users'=>1,'locations'=>1,'companies'=>1], [], $this->at);
        self::assertTrue($enterprise->proposalRequired());
        self::assertNull($enterprise->totalAmount());

        $annualExtra = $this->service->quote('business', 'commerce', 'annual',
            ['users'=>11,'locations'=>3,'companies'=>1], [], $this->at);
        self::assertTrue($annualExtra->proposalRequired());
        self::assertNull($annualExtra->totalAmount());
    }

    public function testQuotePreservesPlanVersionAndComposition(): void
    {
        $q = $this->service->quote('business', 'legal', 'monthly',
            ['users'=>10,'locations'=>3,'companies'=>1], ['production-lite'], $this->at);
        self::assertSame('business', $q->planVersion()->plan()->key());
        self::assertSame(1, $q->planVersion()->version());
        self::assertSame('legal', $q->vertical()->key());
        self::assertSame(['production-lite'], $q->addOns());
        self::assertSame(299800, $q->totalAmount());
        self::assertInstanceOf(Quote::class, $this->manager->find(Quote::class, $q->id()));
    }

    public function testSameVersionAndConfigurationIsDeterministic(): void
    {
        $input = ['users'=>3,'locations'=>1,'companies'=>1];
        $a = $this->service->quote('basic', 'commerce', 'annual', $input, [], $this->at);
        $b = $this->service->quote('basic', 'commerce', 'annual', $input, [], $this->at);
        self::assertSame(799000, $a->totalAmount());
        self::assertSame($a->totalAmount(), $b->totalAmount());
        self::assertSame($a->quantities(), $b->quantities());
    }
}
