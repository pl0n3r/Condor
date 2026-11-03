<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Entity;

use App\Domain\Catalog\Entity\CatalogItem;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Concrete in-memory fixture for the mapped superclass; no Doctrine persistence.
 */
final class CatalogItemProbe extends CatalogItem
{
    public function __construct(Tenant $tenant)
    {
        parent::__construct($tenant);
    }

    public function normalizedName(string $name): string
    {
        return self::normalizeName($name);
    }
}

final class CatalogItemTest extends TestCase
{
    public function testCreatesUniqueUlidsAndRetainsTenant(): void
    {
        $tenant = new Tenant('Empresa', 'catalog-item');
        $first = new CatalogItemProbe($tenant);
        $second = new CatalogItemProbe($tenant);

        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $first->id());
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $second->id());
        self::assertNotSame($first->id(), $second->id());
        self::assertSame($tenant, $first->tenant());
        self::assertTrue($first->isActive());
    }

    public function testTimestampsStartInUtcAndAreInitiallyEqual(): void
    {
        $utc = new DateTimeZone('UTC');
        $before = new DateTimeImmutable('now', $utc);
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-time'));
        $after = new DateTimeImmutable('now', $utc);

        self::assertSame('UTC', $item->createdAt()->getTimezone()->getName());
        self::assertSame('UTC', $item->updatedAt()->getTimezone()->getName());
        self::assertSame(0, $item->createdAt()->getOffset());
        self::assertSame($item->createdAt(), $item->updatedAt());
        self::assertGreaterThanOrEqual($before, $item->createdAt());
        self::assertLessThanOrEqual($after, $item->createdAt());
    }

    public function testDeactivateIsIdempotentAndUpdatesUtcTimestamp(): void
    {
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-deactivate'));
        $createdAt = $item->createdAt();
        $staleUpdatedAt = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
        (new \ReflectionProperty(CatalogItem::class, 'updatedAt'))->setValue($item, $staleUpdatedAt);

        $item->deactivate();

        self::assertFalse($item->isActive());
        self::assertSame($createdAt, $item->createdAt());
        self::assertSame('UTC', $item->updatedAt()->getTimezone()->getName());
        self::assertGreaterThan($staleUpdatedAt, $item->updatedAt());

        $updatedAt = $item->updatedAt();
        $item->deactivate();

        self::assertFalse($item->isActive());
        self::assertSame($createdAt, $item->createdAt());
        self::assertSame($updatedAt, $item->updatedAt());
    }

    public function testNormalizeNameTrimsAndAcceptsExactly160UnicodeCharacters(): void
    {
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-name'));

        self::assertSame('Café premium', $item->normalizedName("  Café premium \t\n"));
        self::assertSame('Café  de origen', $item->normalizedName('  Café  de origen  '));
        self::assertSame(str_repeat('á', 160), $item->normalizedName('  '.str_repeat('á', 160).'  '));
    }

    public function testNormalizeNameRejectsBlankInput(): void
    {
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-empty'));

        $this->expectException(DomainException::class);
        $item->normalizedName(" \t\n ");
    }

    public function testNormalizeNameRejects161AsciiCharactersAfterTrimming(): void
    {
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-ascii-too-long'));

        $this->expectException(DomainException::class);
        $item->normalizedName('  '.str_repeat('a', 161).'  ');
    }

    public function testNormalizeNameRejects161UnicodeCharacters(): void
    {
        $item = new CatalogItemProbe(new Tenant('Empresa', 'catalog-too-long'));

        $this->expectException(DomainException::class);
        $item->normalizedName(str_repeat('á', 161));
    }
}
