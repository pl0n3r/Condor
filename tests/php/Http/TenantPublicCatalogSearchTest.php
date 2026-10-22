<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductCategory;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantPublicCatalogSearchTest extends WebTestCase
{
    public function testHttpSearchPreservesTenantCategoryAndStablePaginationWithoutJavascript(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Tienda de prueba '.$suffix, 'busqueda-'.$suffix);
        $other = new Tenant('Otro comercio '.$suffix, 'otro-'.$suffix);
        $category = new ProductCategory($tenant, 'Ropa', 'ropa');
        $foreignCategory = new ProductCategory($other, 'Ropa externa', 'ropa-externa');
        $manager->persist($tenant);
        $manager->persist($other);
        $manager->persist($category);
        $manager->persist($foreignCategory);
        $manager->persist(new StorefrontProfile($tenant, 'Catálogo de prueba '.$suffix, 'Productos de prueba'));
        $legal = new LegalEntity($tenant, 'Comercio '.$suffix.' SAS', null, true);
        $source = new InventorySource(
            $tenant, $legal, 'Bodega', 'bodega-'.$suffix, InventorySource::TYPE_LOGICAL,
        );
        $prices = new PriceList($tenant, 'Precio web', 'precios-'.$suffix);
        $channel = new SalesChannel($tenant, 'Tienda web', 'tienda-web', $source, $prices);
        foreach ([$legal, $source, $prices, $channel] as $record) {
            $manager->persist($record);
        }

        for ($i = 1; $i <= 26; ++$i) {
            $padded = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $product = new Product($tenant, 'Camisa '.$padded, 'camisa-'.$padded.'-'.$suffix);
            $product->assignCategory($category);
            $variant = new ProductVariant($tenant, $product, 'CAM-'.$padded.'-'.strtoupper($suffix), 'Única');
            $price = new VariantPrice($tenant, $prices, $variant, 150000);
            foreach ([$product, $variant, $price] as $record) {
                $manager->persist($record);
            }
        }
        $hidden = new Product($tenant, 'Camisa invisible', 'hidden-'.$suffix);
        $hidden->assignCategory($category);
        $hidden->deactivate();
        $hiddenVariant = new ProductVariant($tenant, $hidden, 'HID-'.strtoupper($suffix), 'Única');
        $hiddenPrice = new VariantPrice($tenant, $prices, $hiddenVariant, 150000);
        foreach ([$hidden, $hiddenVariant, $hiddenPrice] as $record) {
            $manager->persist($record);
        }
        $unpriced = new Product($tenant, 'Camisa sin precio', 'unpriced-'.$suffix);
        $unpriced->assignCategory($category);
        $manager->persist($unpriced);
        $foreign = new Product($other, 'Camisa ajena', 'foreign-'.$suffix);
        $foreign->assignCategory($foreignCategory);
        $manager->persist($foreign);
        $manager->flush();

        $host = ['HTTP_HOST' => 'www.condorapp.com.co'];
        $path = '/'.$tenant->slug();
        $client->request('GET', $path.'?q=Camisa&categoria=ropa&pagina=1', server: $host);
        self::assertResponseStatusCodeSame(200);
        $first = (string) $client->getResponse()->getContent();
        self::assertSame(24, substr_count($first, 'class="storefront-product"'));
        self::assertStringContainsString('?q=Camisa&amp;categoria=ropa&amp;pagina=2', $first);
        self::assertStringContainsString('name="categoria"', $first);
        self::assertStringContainsString('name="q"', $first);
        self::assertStringNotContainsString('Camisa invisible', $first);
        self::assertStringNotContainsString('Camisa sin precio', $first);
        self::assertStringNotContainsString('Camisa ajena', $first);

        $client->request('GET', $path.'?q=Camisa&categoria=ropa&pagina=2', server: $host);
        self::assertResponseStatusCodeSame(200);
        $second = (string) $client->getResponse()->getContent();
        self::assertSame(2, substr_count($second, 'class="storefront-product"'));
        self::assertStringContainsString('?q=Camisa&amp;categoria=ropa&amp;pagina=1', $second);
        self::assertStringNotContainsString('Camisa 01', $second);
        self::assertStringContainsString('Camisa 25', $second);

        $client->request('GET', $path.'?categoria=ropa-externa&pagina=1', server: $host);
        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('Camisa 01', (string) $client->getResponse()->getContent());

        $client->request('GET', $path.'?q=%25%5F&pagina=1', server: $host);
        self::assertResponseStatusCodeSame(200);
        self::assertStringNotContainsString('Camisa 01', (string) $client->getResponse()->getContent());

        foreach (['?pagina=0', '?pagina=1001', '?q%5B%5D=Camisa', '?categoria=../tenant', '?page=0'] as $invalid) {
            $client->request('GET', $path.$invalid, server: $host);
            self::assertResponseStatusCodeSame(400);
        }
    }
}
