<?php

declare(strict_types=1);

namespace App\Tests\Application\Orders\Support;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;

/** Fixture compartida de tenant/fuente/precio/canal; requiere $entityManager e $inventory. */
trait OrderFixtureTrait
{
    /**
     * @return array{
     *   Tenant,
     *   InventorySource,
     *   PriceList,
     *   SalesChannel,
     *   ProductVariant
     * }
     */
    private function fixture(int $stock, int $price): array
    {
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $legal = new LegalEntity(
            $tenant,
            'Empresa '.$suffix.' SAS',
            null,
            true,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            $legal,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legal,
            'Principal',
            'principal-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product(
            $tenant,
            'Producto '.$suffix,
            'producto-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$suffix,
            'Variante '.$suffix,
        );
        $list = new PriceList(
            $tenant,
            'Lista '.$suffix,
            'lista-'.$suffix,
        );
        $variantPrice = new VariantPrice(
            $tenant,
            $list,
            $variant,
            $price,
        );
        $channel = new SalesChannel(
            $tenant,
            'Web '.$suffix,
            'web-'.$suffix,
            $source,
            $list,
        );

        foreach (
            [
                $tenant,
                $legal,
                $branch,
                $source,
                $product,
                $variant,
                $list,
                $variantPrice,
                $channel,
            ] as $entity
        ) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        if ($stock > 0) {
            $this->inventory->adjust(
                $tenant,
                $source,
                $variant,
                $stock,
                null,
                'seed-'.bin2hex(random_bytes(6)),
            );
        }

        return [$tenant, $source, $list, $channel, $variant];
    }

    private function balance(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): InventoryBalance {
        $balance = $this->entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);

        return $balance;
    }
}
