<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Entity;

use App\Domain\Catalog\Entity\CatalogItem;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductMedia;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ProductMediaTest extends TestCase
{
    public static function acceptedFiles(): iterable
    {
        yield 'JPEG jpg, minimum size' => ['photo.jpg', 'image/jpeg', 1];
        yield 'JPEG jpeg' => ['photo.jpeg', 'image/jpeg', 1024];
        yield 'PNG' => ['photo.png', 'image/png', 100];
        yield 'WebP, maximum size' => ['photo.webp', 'image/webp', 5 * 1024 * 1024];
        yield 'underscore and dash in filename' => ['photo_2026-11.jpg', 'image/jpeg', 1000];
    }

    /** @dataProvider acceptedFiles */
    public function testValidImagesHaveCorrectRelationsAndMetadata(string $name, string $mime, int $bytes): void
    {
        $tenant = new Tenant('Empresa A', 'media-tenant-a');
        $product = new Product($tenant, 'Camiseta', 'camiseta');
        $variant = new ProductVariant($tenant, $product, 'CAM-1', 'Negra');
        $path = self::path($tenant, $name);

        $media = new ProductMedia($tenant, $product, $variant, $path, $mime, $bytes, 0, true);

        self::assertSame($tenant, $media->tenant());
        self::assertSame($product, $media->product());
        self::assertSame($variant, $media->variant());
        self::assertSame($path, $media->filePath());
        self::assertSame($mime, $media->mimeType());
        self::assertSame($bytes, $media->fileSize());
        self::assertSame(0, $media->sortOrder());
        self::assertTrue($media->isPrimary());
        self::assertTrue($media->isActive());
    }

    public function testImageWithoutVariantIsAllowedAndInitiallyNotPrimary(): void
    {
        $tenant = new Tenant('Empresa A', 'media-without-variant');
        $product = new Product($tenant, 'Bolso', 'bolso');

        $media = new ProductMedia($tenant, $product, null, self::path($tenant, 'bolso.jpg'), 'image/jpeg', 20, 7, false);

        self::assertSame($product, $media->product());
        self::assertNull($media->variant());
        self::assertSame(7, $media->sortOrder());
        self::assertFalse($media->isPrimary());
    }

    public function testRejectsMediaAssociatedWithProductInAnotherTenant(): void
    {
        $tenantA = new Tenant('Empresa A', 'media-a');
        $tenantB = new Tenant('Empresa B', 'media-b');
        $productA = new Product($tenantA, 'Camiseta', 'camiseta');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mismo tenant y producto');
        new ProductMedia($tenantB, $productA, null, self::path($tenantB, 'photo.jpg'), 'image/jpeg', 1, 0, false);
    }

    public function testRejectsVariantInAnotherTenant(): void
    {
        $tenantA = new Tenant('Empresa A', 'media-variant-a');
        $tenantB = new Tenant('Empresa B', 'media-variant-b');
        $productA = new Product($tenantA, 'Camiseta', 'camiseta');
        $productB = new Product($tenantB, 'Zapato', 'zapato');
        $variantB = new ProductVariant($tenantB, $productB, 'ZAP-1', 'Azul');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mismo tenant y producto');
        new ProductMedia($tenantA, $productA, $variantB, self::path($tenantA, 'photo.png'), 'image/png', 1, 0, false);
    }

    public function testRejectsVariantFromDifferentProductInSameTenant(): void
    {
        $tenant = new Tenant('Empresa A', 'media-different-product');
        $productA = new Product($tenant, 'Camiseta', 'camiseta');
        $productB = new Product($tenant, 'Zapato', 'zapato');
        $variantB = new ProductVariant($tenant, $productB, 'ZAP-1', 'Azul');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mismo tenant y producto');
        new ProductMedia($tenant, $productA, $variantB, self::path($tenant, 'photo.webp'), 'image/webp', 1, 0, false);
    }

    public static function rejectedFiles(): iterable
    {
        yield 'empty bytes' => ['{TENANT}/photo.jpg', 'image/jpeg', 0];
        yield 'negative bytes' => ['{TENANT}/photo.jpg', 'image/jpeg', -1];
        yield 'oversized file' => ['{TENANT}/photo.jpg', 'image/jpeg', 5 * 1024 * 1024 + 1];
        yield 'unsupported image MIME' => ['{TENANT}/photo.jpg', 'image/gif', 1];
        yield 'SVG content' => ['{TENANT}/photo.svg', 'image/svg+xml', 1];
        yield 'MIME does not match extension' => ['{TENANT}/photo.png', 'image/jpeg', 1];
        yield 'invalid extension' => ['{TENANT}/photo.gif', 'image/png', 1];
        yield 'uppercase suffix is not allowed by path contract' => ['{TENANT}/photo.JPG', 'image/jpeg', 1];
        yield 'another tenant directory' => ['00000000000000000000000000/photo.jpg', 'image/jpeg', 1];
        yield 'traversal' => ['{TENANT}/../photo.jpg', 'image/jpeg', 1];
        yield 'nested folder' => ['{TENANT}/assets/photo.jpg', 'image/jpeg', 1];
        yield 'invalid filename characters' => ['{TENANT}/photo image.jpg', 'image/jpeg', 1];
        yield 'empty filename' => ['{TENANT}/.jpg', 'image/jpeg', 1];
        yield 'path exceeds allowed length' => ['{TENANT}/'.str_repeat('x', 470).'.jpg', 'image/jpeg', 1];
    }

    /** @dataProvider rejectedFiles */
    public function testRejectsUnsafeOrInvalidImages(string $relativePath, string $mime, int $bytes): void
    {
        $tenant = new Tenant('Empresa A', 'media-file-reject');
        $product = new Product($tenant, 'Camiseta', 'camiseta');
        $relativePath = str_replace('{TENANT}', $tenant->id(), $relativePath);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruta local autorizada, formato y tamaño');
        new ProductMedia($tenant, $product, null, '/uploads/catalog/'.$relativePath, $mime, $bytes, 0, false);
    }

    public function testRejectsNegativeOrderOnCreation(): void
    {
        $tenant = new Tenant('Empresa A', 'media-negative-order');
        $product = new Product($tenant, 'Camiseta', 'camiseta');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('orden de la foto no puede ser negativo');
        new ProductMedia($tenant, $product, null, self::path($tenant, 'photo.jpg'), 'image/jpeg', 1, -1, false);
    }

    public function testRejectsNegativeReorderAndPreservesState(): void
    {
        $tenant = new Tenant('Empresa A', 'media-negative-reorder');
        $product = new Product($tenant, 'Camiseta', 'camiseta');
        $media = new ProductMedia($tenant, $product, null, self::path($tenant, 'photo.jpg'), 'image/jpeg', 1, 5, false);

        try {
            $media->reorder(-1);
            self::fail('El orden negativo debe lanzar DomainException.');
        } catch (DomainException $e) {
            self::assertSame('Posición de foto inválida.', $e->getMessage());
        }

        self::assertSame(5, $media->sortOrder());
        self::assertFalse($media->isPrimary());
    }

    public function testReorderOnlyTouchesWhenPositionChanges(): void
    {
        $tenant = new Tenant('Empresa A', 'media-reorder');
        $product = new Product($tenant, 'Camiseta', 'camiseta');
        $media = new ProductMedia($tenant, $product, null, self::path($tenant, 'photo.jpg'), 'image/jpeg', 10, 4, false);
        $createdAt = $media->createdAt();
        $stale = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
        (new ReflectionProperty(CatalogItem::class, 'updatedAt'))->setValue($media, $stale);

        $media->reorder(4);
        self::assertSame($stale, $media->updatedAt());

        $media->reorder(0);
        self::assertSame(0, $media->sortOrder());
        self::assertSame($createdAt, $media->createdAt());
        self::assertGreaterThan($stale, $media->updatedAt());
        $changedAt = $media->updatedAt();

        $media->reorder(0);
        self::assertSame($changedAt, $media->updatedAt());
    }

    public function testSetPrimaryOnlyTouchesWhenValueChanges(): void
    {
        $tenant = new Tenant('Empresa A', 'media-primary');
        $product = new Product($tenant, 'Camiseta', 'camiseta');
        $media = new ProductMedia($tenant, $product, null, self::path($tenant, 'photo.jpg'), 'image/jpeg', 10, 0, false);
        $createdAt = $media->createdAt();
        $stale = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
        (new ReflectionProperty(CatalogItem::class, 'updatedAt'))->setValue($media, $stale);

        $media->setPrimary(false);
        self::assertSame($stale, $media->updatedAt());

        $media->setPrimary(true);
        self::assertTrue($media->isPrimary());
        self::assertSame($createdAt, $media->createdAt());
        self::assertGreaterThan($stale, $media->updatedAt());
        $changedAt = $media->updatedAt();

        $media->setPrimary(true);
        self::assertSame($changedAt, $media->updatedAt());
        $media->setPrimary(false);
        self::assertFalse($media->isPrimary());
    }

    private static function path(Tenant $tenant, string $file): string
    {
        return '/uploads/catalog/'.$tenant->id().'/'.$file;
    }
}
