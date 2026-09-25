<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Inventory\InventoryService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testOwnerCreatesManualOrderAndReplayIsIdempotent(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branch, $owner, $source, $list, $channel, $variant] =
            $this->fixture($entityManager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $key = 'http-order-'.bin2hex(random_bytes(6));
        $payload = [
            'inventory_source_id' => $source->id(),
            'price_list_id' => $list->id(),
            'sales_channel_id' => $channel->id(),
            'customer_id' => null,
            'idempotency_key' => $key,
            'items' => [
                [
                    'variant_id' => $variant->id(),
                    'quantity' => 2,
                ],
            ],
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/orders',
            $payload,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(2000, $created['order']['total_amount_minor']);
        self::assertSame(
            'active',
            $created['order']['lines'][0]['reservation_status'],
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/orders',
            $payload,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);
        $replayed = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $created['order']['id'],
            $replayed['order']['id'],
        );

        self::assertSame(
            1,
            $entityManager->getRepository(Order::class)->count([
                'tenant' => $tenant,
                'idempotencyKey' => $key,
            ]),
        );
        $balance = $entityManager
            ->getRepository(InventoryBalance::class)
            ->findOneBy([
                'tenant' => $tenant,
                'source' => $source,
                'variant' => $variant,
            ]);
        self::assertInstanceOf(InventoryBalance::class, $balance);
        self::assertSame(2, $balance->reservedQuantity());

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/orders',
        );
        self::assertResponseIsSuccessful();
        $index = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $index['orders']);
        self::assertSame(
            $created['order']['id'],
            $index['orders'][0]['id'],
        );
    }

    public function testMutationRequiresCsrf(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [, $branch, $owner] = $this->fixture($entityManager);
        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/orders',
            [],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testBranchUserCannotSeeCancelOrConsumeAnotherBranchOrder(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $branchA, $owner, , $list, , $variant] =
            $this->fixture($entityManager);
        $legal = $branchA->legalEntity();
        self::assertInstanceOf(LegalEntity::class, $legal);

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $branchB = new Branch(
            $tenant,
            'Secundaria',
            'secundaria-'.$suffix,
            $legal,
        );
        $sourceB = new InventorySource(
            $tenant,
            $legal,
            'Secundaria',
            'secundaria-'.$suffix,
            InventorySource::TYPE_BRANCH,
            $branchB,
        );
        $worker = new User(
            'worker-'.$suffix.'@example.test',
            'Vendedor',
        );
        $membership = new Membership($tenant, $worker, 'STAFF');
        $role = new Role(
            $tenant,
            'Pedidos sede '.$suffix,
            ['orders.view', 'orders.update'],
        );
        $assignment = new BranchRoleAssignment(
            $membership,
            $branchA,
            $role,
        );
        foreach (
            [$branchB, $sourceB, $worker, $membership, $role, $assignment]
            as $entity
        ) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        (new InventoryService($entityManager))->adjust(
            $tenant,
            $sourceB,
            $variant,
            3,
            $owner->id(),
            'seed-other-branch-'.bin2hex(random_bytes(6)),
        );

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branchA->id().'/orders',
            [
                'inventory_source_id' => $sourceB->id(),
                'price_list_id' => $list->id(),
                'sales_channel_id' => null,
                'customer_id' => null,
                'idempotency_key' => 'other-branch-'.bin2hex(random_bytes(6)),
                'items' => [[
                    'variant_id' => $variant->id(),
                    'quantity' => 1,
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $orderId = $created['order']['id'];

        $client->loginUser($worker);
        $csrf = $this->csrf($client);

        $client->request(
            'GET',
            '/api/v1/branches/'.$branchA->id().'/orders',
        );
        self::assertResponseIsSuccessful();
        $index = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame([], $index['orders']);

        foreach (['cancel', 'consume'] as $action) {
            $client->request(
                'POST',
                '/api/v1/branches/'.$branchA->id()
                    .'/orders/'.$orderId.'/'.$action,
                server: ['HTTP_X_CSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(404);
        }
    }

    /**
     * @return array{
     *   Tenant,
     *   Branch,
     *   User,
     *   InventorySource,
     *   PriceList,
     *   SalesChannel,
     *   ProductVariant
     * }
     */
    private function fixture(EntityManagerInterface $entityManager): array
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
        $owner = new User(
            'orders-'.$suffix.'@example.test',
            'Propietario',
        );
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
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
        $price = new VariantPrice(
            $tenant,
            $list,
            $variant,
            1000,
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
                $owner,
                $membership,
                $source,
                $product,
                $variant,
                $list,
                $price,
                $channel,
            ] as $entity
        ) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $inventory = new InventoryService($entityManager);
        $inventory->adjust(
            $tenant,
            $source,
            $variant,
            5,
            $owner->id(),
            'seed-http-'.bin2hex(random_bytes(6)),
        );

        return [
            $tenant,
            $branch,
            $owner,
            $source,
            $list,
            $channel,
            $variant,
        ];
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
}
