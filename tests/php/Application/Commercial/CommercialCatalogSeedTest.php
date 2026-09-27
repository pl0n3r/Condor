<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommercialCatalogSeedTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommercialCatalogSeeder $seeder;
    private CommercialCatalogReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $manager = $container->get(EntityManagerInterface::class);
        $seeder = $container->get(CommercialCatalogSeeder::class);
        $reader = $container->get(CommercialCatalogReader::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        self::assertInstanceOf(CommercialCatalogReader::class, $reader);
        $this->entityManager = $manager;
        $this->seeder = $seeder;
        $this->reader = $reader;
    }

    public function testCapabilitiesAndAddOnsUseStableKeys(): void
    {
        $this->seeder->seed();
        $capability = $this->entityManager->getRepository(Capability::class)
            ->findOneBy(['key' => 'api-webhooks']);
        $addOn = $this->entityManager->getRepository(AddOn::class)
            ->findOneBy(['key' => 'production-lite']);

        self::assertInstanceOf(Capability::class, $capability);
        self::assertInstanceOf(AddOn::class, $addOn);
        self::assertSame('api-webhooks', $capability->key());
        self::assertSame('production-lite', $addOn->key());
    }

    public function testInitialCatalogMatchesApprovedPlansAndPrices(): void
    {
        $this->seeder->seed();
        $catalog = $this->index($this->reader->current(
            new DateTimeImmutable('2026-09-27T12:00:00Z'),
        ));

        self::assertSame(79900, $catalog['basic']['monthly_amount']);
        self::assertSame(199900, $catalog['business']['monthly_amount']);
        self::assertSame(499900, $catalog['pro']['monthly_amount']);
        self::assertTrue($catalog['enterprise']['quote_required']);
        self::assertNull($catalog['enterprise']['monthly_amount']);
        self::assertContains('legal', $catalog['business']['verticals']);
    }

    public function testProductionLiteIsBusinessAddOn(): void
    {
        $this->seeder->seed();
        $catalog = $this->index($this->reader->current(
            new DateTimeImmutable('2026-09-27T12:00:00Z'),
        ));
        $business = array_column($catalog['business']['addons'], null, 'key');
        $pro = array_column($catalog['pro']['addons'], null, 'key');

        self::assertSame(99900, $business['production-lite']['monthly_amount']);
        self::assertArrayNotHasKey('production-lite', $pro);
    }

    public function testSeedIsIdempotentAndReaderResolvesEffectiveCatalog(): void
    {
        $this->seeder->seed();
        $this->seeder->seed();

        self::assertCount(17, $this->entityManager->getRepository(Capability::class)->findAll());
        self::assertCount(6, $this->entityManager->getRepository(AddOn::class)->findAll());
        self::assertCount(4, $this->reader->current(
            new DateTimeImmutable('2026-09-27T12:00:00Z'),
        ));
    }

    /** @param list<array<string,mixed>> $catalog @return array<string,array<string,mixed>> */
    private function index(array $catalog): array
    {
        $indexed = [];
        foreach ($catalog as $item) {
            $indexed[$item['key']] = $item;
        }
        return $indexed;
    }
}
