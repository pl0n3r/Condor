<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductionOperationsControllerTest extends WebTestCase
{
    public function testBomSnapshotAndVersionCreationAreTenantScopedAndEntitled(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();

        [
            $tenant,
            ,
            $branch,
            $owner,
            ,
            $variant,
            $material,
        ] = $this->tenantWithProduction($manager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/production/boms';

        $client->jsonRequest(
            'POST',
            $base,
            [
                'variant_id' => $variant->id(),
                'components' => [[
                    'material_id' => $material->id(),
                    'quantity' => '1.5',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $first = $this->json($client)['bom'];
        self::assertSame(1, $first['version']);
        self::assertTrue($first['active']);
        self::assertSame('1.5', $first['lines'][0]['quantity']);
        self::assertSame('kg', $first['lines'][0]['unit']);

        $client->jsonRequest(
            'POST',
            $base,
            [
                'variant_id' => $variant->id(),
                'components' => [[
                    'material_id' => $material->id(),
                    'quantity' => '2000',
                    'unit' => 'g',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $second = $this->json($client)['bom'];
        self::assertSame(2, $second['version']);
        self::assertSame('2', $second['lines'][0]['quantity']);

        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $snapshot = $this->json($client);
        self::assertCount(2, $snapshot['boms']);

        $byVersion = [];
        foreach ($snapshot['boms'] as $bom) {
            $byVersion[$bom['version']] = $bom;
        }
        self::assertFalse($byVersion[1]['active']);
        self::assertTrue($byVersion[2]['active']);

        [$foreignTenant, , , , , $foreignVariant, $foreignMaterial] =
            $this->tenantWithProduction($manager);
        $foreignBom = new BillOfMaterials(
            $foreignTenant,
            $foreignVariant,
            1,
            [[
                'material' => $foreignMaterial,
                'quantity' => '1',
                'unit' => UnitOfMeasure::from('kg'),
            ]],
        );
        $manager->persist($foreignBom);
        $manager->flush();

        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        foreach ($this->json($client)['boms'] as $bom) {
            self::assertNotSame($foreignBom->id(), $bom['id']);
        }

        self::assertSame($tenant->id(), $variant->tenant()->id());
    }

    public function testOrderCreateAndCompleteUseBranchSourceDomainServicesAndIdempotency(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();

        [
            $tenant,
            ,
            $branch,
            $owner,
            $source,
            $variant,
            $material,
        ] = $this->tenantWithProduction($manager);
        $manager->persist(new MaterialInventoryBalance(
            $tenant,
            $source,
            $material,
            '10',
        ));
        $manager->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/production';

        $client->jsonRequest(
            'POST',
            $base.'/boms',
            [
                'variant_id' => $variant->id(),
                'components' => [[
                    'material_id' => $material->id(),
                    'quantity' => '2',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $bom = $this->json($client)['bom'];

        $client->jsonRequest(
            'POST',
            $base.'/orders',
            [
                'bom_id' => $bom['id'],
                'target_quantity' => 3,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $order = $this->json($client)['order'];
        self::assertSame('draft', $order['status']);
        self::assertSame($source->id(), $order['source_id']);
        self::assertSame($variant->id(), $order['variant_id']);

        $key = 'complete-http-'.bin2hex(random_bytes(5));
        $completion = [
            'completed_quantity' => 3,
            'idempotency_key' => $key,
        ];
        $completeUrl = $base.'/orders/'.$order['id'].'/complete';
        $client->jsonRequest(
            'POST',
            $completeUrl,
            $completion,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $completed = $this->json($client)['order'];
        self::assertSame('completed', $completed['status']);
        self::assertSame(3, $completed['completed_quantity']);
        self::assertSame($key, $completed['completion_idempotency_key']);

        $client->jsonRequest(
            'POST',
            $completeUrl,
            $completion,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame('completed', $this->json($client)['order']['status']);

        self::assertSame(
            '4.000000',
            (string) $manager->getConnection()->fetchOne(
                'SELECT quantity FROM condor_production_material_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND material_id = ?',
                [$tenant->id(), $source->id(), $material->id()],
            ),
        );
        self::assertSame(
            '3',
            (string) $manager->getConnection()->fetchOne(
                'SELECT quantity FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [$tenant->id(), $source->id(), $variant->id()],
            ),
        );

        $client->request('GET', $base.'/orders');
        self::assertResponseIsSuccessful();
        $orders = $this->json($client)['orders'];
        self::assertCount(1, $orders);
        self::assertSame($order['id'], $orders[0]['id']);
    }

    public function testPermissionsCsrfCrossTenantStaleBomOrForeignOrderFailClosed(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();

        [
            $tenantA,
            ,
            $branchA,
            $ownerA,
            $sourceA,
            $variantA,
            $materialA,
        ] = $this->tenantWithProduction($manager);
        $manager->persist(new MaterialInventoryBalance(
            $tenantA,
            $sourceA,
            $materialA,
            '20',
        ));
        $manager->flush();

        $viewer = new User(
            'production-ops-viewer-'.bin2hex(random_bytes(4)).'@example.test',
            'Viewer producción',
        );
        $membership = new Membership($tenantA, $viewer, 'ADMIN');
        $role = new Role($tenantA, 'Producción lectura', ['inventory.view']);
        $assignment = new BranchRoleAssignment(
            $membership,
            $branchA,
            $role,
        );
        foreach ([$viewer, $membership, $role, $assignment] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $baseA = '/api/v1/branches/'.$branchA->id().'/production';
        $client->loginUser($viewer);
        $client->jsonRequest(
            'POST',
            $baseA.'/boms',
            [
                'variant_id' => $variantA->id(),
                'components' => [[
                    'material_id' => $materialA->id(),
                    'quantity' => '1',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($ownerA);
        $client->jsonRequest(
            'POST',
            $baseA.'/boms',
            [
                'variant_id' => $variantA->id(),
                'components' => [[
                    'material_id' => $materialA->id(),
                    'quantity' => '1',
                    'unit' => 'kg',
                ]],
            ],
        );
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->csrf($client);
        $client->jsonRequest(
            'POST',
            $baseA.'/boms',
            [
                'variant_id' => $variantA->id(),
                'components' => [[
                    'material_id' => $materialA->id(),
                    'quantity' => '1',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $stale = $this->json($client)['bom'];

        $client->jsonRequest(
            'POST',
            $baseA.'/boms',
            [
                'variant_id' => $variantA->id(),
                'components' => [[
                    'material_id' => $materialA->id(),
                    'quantity' => '2',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest(
            'POST',
            $baseA.'/orders',
            [
                'bom_id' => $stale['id'],
                'target_quantity' => 1,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(422);

        [
            $tenantB,
            ,
            $branchB,
            $ownerB,
            ,
            $variantB,
            $materialB,
        ] = $this->tenantWithProduction($manager);
        $client->loginUser($ownerB);
        $csrfB = $this->csrf($client);
        $baseB = '/api/v1/branches/'.$branchB->id().'/production';

        $client->jsonRequest(
            'POST',
            $baseB.'/boms',
            [
                'variant_id' => $variantB->id(),
                'components' => [[
                    'material_id' => $materialB->id(),
                    'quantity' => '1',
                    'unit' => 'kg',
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrfB],
        );
        self::assertResponseStatusCodeSame(201);
        $foreignBom = $this->json($client)['bom'];

        $client->jsonRequest(
            'POST',
            $baseB.'/orders',
            [
                'bom_id' => $foreignBom['id'],
                'target_quantity' => 1,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrfB],
        );
        self::assertResponseStatusCodeSame(201);
        $foreignOrder = $this->json($client)['order'];

        $client->loginUser($ownerA);
        $client->jsonRequest(
            'POST',
            $baseA.'/orders/'.$foreignOrder['id'].'/complete',
            [
                'completed_quantity' => 1,
                'idempotency_key' => 'foreign-'.bin2hex(random_bytes(4)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(404);

        self::assertNotSame($tenantA->id(), $tenantB->id());
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /** @return array<string,mixed> */
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
     * @return array{
     *   Tenant,
     *   LegalEntity,
     *   Branch,
     *   User,
     *   InventorySource,
     *   ProductVariant,
     *   Material
     * }
     */
    private function tenantWithProduction(
        EntityManagerInterface $manager,
    ): array {
        $seeder = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Producción ops '.$suffix, 'prod-ops-'.$suffix);
        $legalEntity = new LegalEntity(
            $tenant,
            'Producción ops '.$suffix.' SAS',
            null,
            true,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            $legalEntity,
            true,
        );
        $owner = new User(
            'owner-prod-ops-'.$suffix.'@example.test',
            'Owner producción',
        );
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
        );
        $source = new InventorySource(
            $tenant,
            $legalEntity,
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
            'Única',
        );
        $material = new Material(
            $tenant,
            'MAT-'.$suffix,
            'Materia '.$suffix,
            UnitOfMeasure::from('kg'),
        );

        foreach (
            [
                $tenant,
                $legalEntity,
                $branch,
                $owner,
                $membership,
                $source,
                $product,
                $variant,
                $material,
            ] as $entity
        ) {
            $manager->persist($entity);
        }
        $manager->flush();

        $plan = $manager->getRepository(Plan::class)
            ->findOneBy(['key' => 'business']);
        self::assertInstanceOf(Plan::class, $plan);
        $version = $manager->getRepository(PlanVersion::class)
            ->findOneBy(['plan' => $plan, 'version' => 1]);
        self::assertInstanceOf(PlanVersion::class, $version);
        $vertical = $manager->getRepository(Vertical::class)
            ->findOneBy(['key' => 'commerce']);
        self::assertInstanceOf(Vertical::class, $vertical);
        $production = $manager->getRepository(AddOn::class)
            ->findOneBy(['key' => 'production-lite']);
        self::assertInstanceOf(AddOn::class, $production);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $tenant->id(),
                $version,
                SubscriptionState::Active,
                $now,
            ),
            $now,
        );
        $quantities = [];
        foreach ($version->limits() as $key => $value) {
            if (is_int($value)) {
                $quantities[$key] = $value;
            }
        }
        $configuration = new SubscriptionConfiguration(
            $subscription,
            $vertical,
            $quantities,
            [$production],
            $now,
        );
        $manager->persist($subscription);
        $manager->persist($configuration);
        $manager->flush();

        return [
            $tenant,
            $legalEntity,
            $branch,
            $owner,
            $source,
            $variant,
            $material,
        ];
    }
}
