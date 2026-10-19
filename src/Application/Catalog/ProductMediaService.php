<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductMedia;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Registro de metadatos de fotos ya validadas y almacenadas por el upload autenticado. */
final readonly class ProductMediaService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function add(
        Tenant $tenant,
        Product $product,
        ?ProductVariant $variant,
        string $localUploadPath,
        string $mime,
        int $size,
    ): ProductMedia {
        self::assertScope($tenant, $product, $variant);
        return $this->entityManager->wrapInTransaction(function () use (
            $tenant, $product, $variant, $localUploadPath, $mime, $size
        ): ProductMedia {
            $this->entityManager->lock($product, LockMode::PESSIMISTIC_WRITE);
            $photos = $this->byScope($tenant, $product, $variant);
            $photo = new ProductMedia(
                $tenant, $product, $variant, $localUploadPath, $mime, $size,
                count($photos), $photos === [],
            );
            $this->entityManager->persist($photo);
            return $photo;
        });
    }

    public function makePrimary(Tenant $tenant, Product $product, ProductMedia $selected): void
    {
        self::assertScope($tenant, $product, $selected->variant());
        $this->entityManager->wrapInTransaction(function () use ($tenant, $product, $selected): void {
            $this->entityManager->lock($product, LockMode::PESSIMISTIC_WRITE);
            $photos = $this->byScope($tenant, $product, $selected->variant());
            $found = false;
            foreach ($photos as $photo) {
                if ($photo->id() === $selected->id()) $found = true;
                $photo->setPrimary($photo->id() === $selected->id());
            }
            if (!$found) throw new DomainException('Foto no disponible para este tenant/producto.');
        });
    }

    /** @param list<string> $orderedIds */
    public function reorder(Tenant $tenant, Product $product, ?ProductVariant $variant, array $orderedIds): void
    {
        self::assertScope($tenant, $product, $variant);
        $this->entityManager->wrapInTransaction(function () use ($tenant, $product, $variant, $orderedIds): void {
            $this->entityManager->lock($product, LockMode::PESSIMISTIC_WRITE);
            $photos = $this->byScope($tenant, $product, $variant);
            $mapped = [];
            foreach ($photos as $photo) $mapped[$photo->id()] = $photo;
            if (count($orderedIds) !== count($photos)
                || count(array_unique($orderedIds)) !== count($orderedIds)
                || array_diff($orderedIds, array_keys($mapped)) !== []
            ) {
                throw new DomainException('La lista de fotos no coincide con el producto.');
            }
            foreach ($orderedIds as $position => $id) $mapped[$id]->reorder($position);
        });
    }

    /** @return list<ProductMedia> */
    public function byScope(Tenant $tenant, Product $product, ?ProductVariant $variant = null): array
    {
        self::assertScope($tenant, $product, $variant);
        return $this->entityManager->getRepository(ProductMedia::class)->findBy(
            ['tenant' => $tenant, 'product' => $product, 'variant' => $variant, 'active' => true],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
    }

    private static function assertScope(Tenant $tenant, Product $product, ?ProductVariant $variant): void
    {
        if (!$product->isActive() || $product->tenant()->id() !== $tenant->id()
            || ($variant !== null && (!$variant->isActive()
                || $variant->tenant()->id() !== $tenant->id()
                || $variant->product()->id() !== $product->id()))
        ) {
            throw new DomainException('Foto fuera del tenant, producto o variante autorizados.');
        }
    }
}
