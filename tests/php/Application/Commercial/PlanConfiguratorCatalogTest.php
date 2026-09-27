<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\PlanConfiguratorCatalogReader;
use App\Domain\Commercial\Entity\VerticalCapability;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanConfiguratorCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommercialCatalogSeeder $seeder;
    private CommercialCatalogReader $catalog;
    private PlanConfiguratorCatalogReader $reader;
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->seeder = new CommercialCatalogSeeder($manager);
        $this->catalog = new CommercialCatalogReader(
            $manager,
            new PlanVersionTimeline(),
        );
        $this->reader = new PlanConfiguratorCatalogReader($this->catalog, $manager);
        $this->at = new DateTimeImmutable('2026-09-27T12:00:00Z');
    }

    public function testConfiguratorReadsCanonicalCatalog(): void
    {
        $this->seeder->seed();
        $options = $this->reader->options('business', 'legal', $this->at);

        self::assertSame('business', $options['plan']['key']);
        self::assertSame(1, $options['plan']['version']);
        self::assertSame(199900, $options['plan']['monthly_amount']);
        self::assertSame('COP', $options['plan']['currency']);
        self::assertSame(
            ['companies' => 1, 'locations' => 3, 'users' => 10],
            $options['limits'],
        );
    }

    public function testVerticalCompatibilityUsesStableRelations(): void
    {
        $this->seeder->seed();
        $relation = $this->entityManager
            ->getRepository(VerticalCapability::class)
            ->findOneBy(['key' => 'legal--contacts']);

        self::assertInstanceOf(VerticalCapability::class, $relation);
        self::assertSame('legal', $relation->vertical()->key());
        self::assertSame('contacts', $relation->capability()->key());
        self::assertSame(1, $relation->priority());

        $this->expectException(DomainException::class);
        $this->reader->options('business', 'unknown-vertical', $this->at);
    }

    public function testLegalVerticalExcludesInventoryAndManufacturing(): void
    {
        $this->seeder->seed();
        $options = $this->reader->options('pro', 'legal', $this->at);
        $keys = array_column($options['capabilities'], 'key');

        self::assertSame(
            ['contacts', 'legal-cases', 'documents', 'calendar-deadlines'],
            array_slice($keys, 0, 4),
        );
        self::assertNotContains('inventory', $keys);
        self::assertNotContains('manufacturing', $keys);
    }

    public function testSwitchingVerticalChangesRelevantOptionsWithoutPlanFork(): void
    {
        $this->seeder->seed();
        $commerce = $this->reader->options('pro', 'commerce', $this->at);
        $legal = $this->reader->options('pro', 'legal', $this->at);

        self::assertSame($commerce['plan']['key'], $legal['plan']['key']);
        self::assertSame($commerce['plan']['version'], $legal['plan']['version']);
        self::assertContains('inventory', array_column($commerce['capabilities'], 'key'));
        self::assertNotContains('inventory', array_column($legal['capabilities'], 'key'));
        self::assertContains('legal-cases', array_column($legal['capabilities'], 'key'));
        self::assertNotContains('legal-cases', array_column($commerce['capabilities'], 'key'));
    }

    public function testAddonsComeFromPlanVersionRelations(): void
    {
        $this->seeder->seed();
        $businessLegal = $this->reader->options('business', 'legal', $this->at);
        $businessCommerce = $this->reader->options('business', 'commerce', $this->at);
        $proLegal = $this->reader->options('pro', 'legal', $this->at);

        self::assertSame($businessLegal['addons'], $businessCommerce['addons']);
        self::assertContains(
            'production-lite',
            array_column($businessLegal['addons'], 'key'),
        );
        self::assertNotContains(
            'production-lite',
            array_column($proLegal['addons'], 'key'),
        );

        $catalog = [];
        foreach ($this->catalog->current($this->at) as $plan) {
            $catalog[$plan['key']] = $plan;
        }
        self::assertSame($catalog['business']['addons'], $businessLegal['addons']);
    }

    public function testCompatibilityMigrationAndSeedAreIdempotent(): void
    {
        $this->seeder->seed();
        $repository = $this->entityManager->getRepository(VerticalCapability::class);
        $firstCount = $repository->count([]);

        $this->seeder->seed();
        self::assertSame($firstCount, $repository->count([]));
        self::assertSame(61, $firstCount);

        $table = $this->entityManager
            ->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_commercial_vertical_capability');
        self::assertTrue($table->hasColumn('relation_key'));
        self::assertTrue($table->hasColumn('priority'));
        self::assertTrue($table->hasIndex('uniq_commercial_vertical_capability_key'));
        self::assertTrue($table->hasIndex('uniq_commercial_vertical_capability_pair'));

        $references = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $references[] = $foreignKey->getReferencedTableName()->toString();
        }
        sort($references);
        self::assertSame(
            ['condor_commercial_capability', 'condor_commercial_vertical'],
            $references,
        );
    }
}
