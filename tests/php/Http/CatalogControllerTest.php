<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    public function testOwnerCanCreateVariantEditAndListCatalog(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [$tenant, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );

        $client->loginUser($owner);
        $csrf = $this->csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
            [
                'name' => 'Camiseta negra',
                'slug' => 'camiseta-negra',
                'description' => 'Algodón pesado.',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $productPayload = $this->json($client);
        $productId = $productPayload['product']['id'];
        self::assertFalse($productPayload['product']['allow_backorder']);

        $client->jsonRequest(
            'PATCH',
            '/api/v1/branches/'.$branch->id()
                .'/catalog/products/'.$productId,
            ['allow_backorder' => true],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['product']['allow_backorder']);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id()
                .'/catalog/products/'.$productId.'/variants',
            ['sku' => 'tee-black-m', 'name' => 'Talla M'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $variantPayload = $this->json($client);
        $variantId = $variantPayload['variant']['id'];
        self::assertSame('TEE-BLACK-M', $variantPayload['variant']['sku']);

        $client->jsonRequest(
            'PATCH',
            '/api/v1/branches/'.$branch->id()
                .'/catalog/products/'.$productId.'/variants/'.$variantId,
            ['name' => 'Talla M / Regular'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
        );
        self::assertResponseIsSuccessful();

        $catalog = $this->json($client);
        self::assertCount(1, $catalog['products']);
        $storedProduct = $entityManager
            ->getRepository(Product::class)
            ->find($productId);
        self::assertInstanceOf(Product::class, $storedProduct);
        self::assertSame($tenant->id(), $storedProduct->tenant()->id());
        self::assertSame('Camiseta negra', $catalog['products'][0]['name']);
        self::assertTrue($catalog['products'][0]['allow_backorder']);
        self::assertTrue($storedProduct->allowsBackorder());
        self::assertSame(
            'Talla M / Regular',
            $catalog['products'][0]['variants'][0]['name'],
        );
    }

    public function testDelegatedUserNeedsCatalogCreatePermission(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [$tenant, $branch, $user, $membership] = $this->tenantWithUser(
            $entityManager,
            'ADMIN',
            true,
        );
        $viewer = new Role($tenant, 'Solo catálogo', ['catalog.view']);
        $entityManager->persist($viewer);
        $entityManager->persist(
            new BranchRoleAssignment($membership, $branch, $viewer),
        );
        $entityManager->flush();

        $client->loginUser($user);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
            ['name' => 'No permitido', 'slug' => 'no-permitido'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertNull(
            $entityManager->getRepository(Product::class)->findOneBy([
                'tenant' => $tenant,
                'slug' => 'no-permitido',
            ]),
        );
    }

    public function testCrossTenantProductIdIsNotResolved(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $otherTenant = new Tenant(
            'Otra empresa',
            'otra-'.bin2hex(random_bytes(4)),
        );
        $otherProduct = new Product(
            $otherTenant,
            'Producto ajeno',
            'producto-ajeno',
        );
        $entityManager->persist($otherTenant);
        $entityManager->persist($otherProduct);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id()
                .'/catalog/products/'.$otherProduct->id().'/variants',
            ['sku' => 'AJENO-1', 'name' => 'No visible'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertNull(
            $entityManager->getRepository(ProductVariant::class)->findOneBy([
                'tenant' => $otherTenant,
                'sku' => 'AJENO-1',
            ]),
        );
    }

    public function testDuplicateProductSlugAndVariantSkuReturnConflict(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $client->loginUser($owner);
        $csrf = $this->csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
            ['name' => 'Producto A', 'slug' => 'producto'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $productId = $this->json($client)['product']['id'];

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
            ['name' => 'Producto B', 'slug' => 'producto'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(409);

        $variantUrl = '/api/v1/branches/'.$branch->id()
            .'/catalog/products/'.$productId.'/variants';
        $client->jsonRequest(
            'POST',
            $variantUrl,
            ['sku' => 'SKU-1', 'name' => 'Uno'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest(
            'POST',
            $variantUrl,
            ['sku' => 'sku-1', 'name' => 'Duplicada'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testMutationsRequireCsrfAndRejectUnexpectedFields(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $client->loginUser($owner);
        $url = '/api/v1/branches/'.$branch->id().'/catalog/products';

        $client->jsonRequest(
            'POST',
            $url,
            ['name' => 'Sin CSRF', 'slug' => 'sin-csrf'],
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'POST',
            $url,
            [
                'name' => 'Payload inválido',
                'slug' => 'payload-invalido',
                'tenant_id' => 'no-controlado-por-cliente',
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->json($client)['error']);
    }

    public function testDeletingProductAlsoHidesItsActiveVariants(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        [$tenant, $branch, $owner] = $this->tenantWithUser(
            $entityManager,
            Membership::ROLE_OWNER,
        );
        $product = new Product($tenant, 'Producto', 'producto');
        $variant = new ProductVariant($tenant, $product, 'SKU-1', 'Única');
        $entityManager->persist($product);
        $entityManager->persist($variant);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request(
            'DELETE',
            '/api/v1/branches/'.$branch->id()
                .'/catalog/products/'.$product->id(),
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(204);

        $storedProduct = $entityManager
            ->getRepository(Product::class)
            ->find($product->id());
        $storedVariant = $entityManager
            ->getRepository(ProductVariant::class)
            ->find($variant->id());

        self::assertInstanceOf(Product::class, $storedProduct);
        self::assertInstanceOf(ProductVariant::class, $storedVariant);
        self::assertFalse($storedProduct->isActive());
        self::assertFalse($storedVariant->isActive());

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/catalog/products',
        );
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['products']);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload;
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter('#condor-admin-root')
            ->attr('data-access-token');

        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{0: Tenant, 1: Branch, 2: User, 3?: Membership}
     */
    private function tenantWithUser(
        EntityManagerInterface $entityManager,
        string $roleKey,
        bool $includeMembership = false,
    ): array {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            'Empresa '.$suffix,
            'empresa-'.$suffix,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal',
            null,
            true,
        );
        $user = new User(
            'user-'.$suffix.'@example.test',
            'Usuario',
        );
        $membership = new Membership($tenant, $user, $roleKey);

        foreach ([$tenant, $branch, $user, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        if ($includeMembership) {
            return [$tenant, $branch, $user, $membership];
        }

        return [$tenant, $branch, $user];
    }
}
