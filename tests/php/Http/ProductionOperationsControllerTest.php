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
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Tests\Support\BrowserCsrfToken;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductionOperationsControllerTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testBomSnapshotAndVersionCreationAreTenantScopedAndEntitled(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $fx = $this->fixture($em);
        $client->loginUser($fx['owner']);
        $token = $this->branchToken($client);
        $url = $this->api($fx['branch'], 'boms');

        $first = $this->postBom(
            $client,
            $url,
            $token,
            $fx['variant'],
            $fx['material'],
            '1.5',
            'kg',
        );
        self::assertSame(1, $first['version']);
        self::assertTrue($first['active']);
        self::assertSame('1.5', $first['lines'][0]['quantity']);

        $second = $this->postBom(
            $client,
            $url,
            $token,
            $fx['variant'],
            $fx['material'],
            '2000',
            'g',
        );
        self::assertSame(2, $second['version']);
        self::assertSame('2', $second['lines'][0]['quantity']);

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $versions = [];
        foreach ($this->body($client)['boms'] as $bom) {
            $versions[$bom['version']] = $bom;
        }
        self::assertFalse($versions[1]['active']);
        self::assertTrue($versions[2]['active']);

        $foreign = $this->fixture($em);
        $foreignBom = new BillOfMaterials(
            $foreign['tenant'],
            $foreign['variant'],
            1,
            [[
                'material' => $foreign['material'],
                'quantity' => '1',
                'unit' => UnitOfMeasure::from('kg'),
            ]],
        );
        $em->persist($foreignBom);
        $em->flush();

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $ids = array_column($this->body($client)['boms'], 'id');
        self::assertNotContains($foreignBom->id(), $ids);
    }

    public function testOrderCreateAndCompleteUseBranchSourceDomainServicesAndIdempotency(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $fx = $this->fixture($em);
        $em->persist(new MaterialInventoryBalance(
            $fx['tenant'],
            $fx['source'],
            $fx['material'],
            '10',
        ));
        $em->flush();

        $client->loginUser($fx['owner']);
        $token = $this->branchToken($client);
        $base = $this->api($fx['branch']);

        $bom = $this->postBom(
            $client,
            $base.'/boms',
            $token,
            $fx['variant'],
            $fx['material'],
            '2',
            'kg',
        );

        $client->jsonRequest(
            'POST',
            $base.'/orders',
            ['bom_id' => $bom['id'], 'target_quantity' => 3],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(201);
        $order = $this->body($client)['order'];
        self::assertSame('draft', $order['status']);
        self::assertSame($fx['source']->id(), $order['source_id']);

        $key = 'complete-http-'.bin2hex(random_bytes(5));
        $completion = [
            'completed_quantity' => 3,
            'idempotency_key' => $key,
        ];
        $complete = $base.'/orders/'.$order['id'].'/complete';

        $client->jsonRequest(
            'POST',
            $complete,
            $completion,
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(201);
        $done = $this->body($client)['order'];
        self::assertSame('completed', $done['status']);
        self::assertSame($key, $done['completion_idempotency_key']);

        $client->jsonRequest(
            'POST',
            $complete,
            $completion,
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(200);

        $db = $em->getConnection();
        self::assertSame(
            '4.000000',
            (string) $db->fetchOne(
                'SELECT quantity FROM condor_production_material_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND material_id = ?',
                [
                    $fx['tenant']->id(),
                    $fx['source']->id(),
                    $fx['material']->id(),
                ],
            ),
        );
        self::assertSame(
            '3',
            (string) $db->fetchOne(
                'SELECT quantity FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [
                    $fx['tenant']->id(),
                    $fx['source']->id(),
                    $fx['variant']->id(),
                ],
            ),
        );

        $client->request('GET', $base.'/orders');
        self::assertResponseIsSuccessful();
        self::assertSame(
            $order['id'],
            $this->body($client)['orders'][0]['id'],
        );
    }

    public function testPermissionsCsrfCrossTenantStaleBomOrForeignOrderFailClosed(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $local = $this->fixture($em);
        $base = $this->api($local['branch']);

        $viewer = new User(
            'ops-viewer-'.bin2hex(random_bytes(4)).'@example.test',
            'Viewer',
        );
        $membership = new Membership($local['tenant'], $viewer, 'ADMIN');
        $role = new Role(
            $local['tenant'],
            'Producción lectura',
            ['inventory.view'],
        );
        $this->persist(
            $em,
            [
                $viewer,
                $membership,
                $role,
                new BranchRoleAssignment(
                    $membership,
                    $local['branch'],
                    $role,
                ),
            ],
        );

        $client->loginUser($viewer);
        $client->jsonRequest(
            'POST',
            $base.'/boms',
            $this->bomRequest($local['variant'], $local['material'], '1'),
            ['HTTP_X_CSRF_TOKEN' => $this->branchToken($client)],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($local['owner']);
        $client->jsonRequest(
            'POST',
            $base.'/boms',
            $this->bomRequest($local['variant'], $local['material'], '1'),
        );
        self::assertResponseStatusCodeSame(403);

        $token = $this->branchToken($client);
        $stale = $this->postBom(
            $client,
            $base.'/boms',
            $token,
            $local['variant'],
            $local['material'],
            '1',
            'kg',
        );
        $this->postBom(
            $client,
            $base.'/boms',
            $token,
            $local['variant'],
            $local['material'],
            '2',
            'kg',
        );

        $client->jsonRequest(
            'POST',
            $base.'/orders',
            ['bom_id' => $stale['id'], 'target_quantity' => 1],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(422);

        $foreign = $this->fixture($em);
        $client->loginUser($foreign['owner']);
        $foreignToken = $this->branchToken($client);
        $foreignBase = $this->api($foreign['branch']);
        $foreignBom = $this->postBom(
            $client,
            $foreignBase.'/boms',
            $foreignToken,
            $foreign['variant'],
            $foreign['material'],
            '1',
            'kg',
        );
        $client->jsonRequest(
            'POST',
            $foreignBase.'/orders',
            ['bom_id' => $foreignBom['id'], 'target_quantity' => 1],
            ['HTTP_X_CSRF_TOKEN' => $foreignToken],
        );
        self::assertResponseStatusCodeSame(201);
        $foreignOrder = $this->body($client)['order'];

        $client->loginUser($local['owner']);
        $client->jsonRequest(
            'POST',
            $base.'/orders/'.$foreignOrder['id'].'/complete',
            [
                'completed_quantity' => 1,
                'idempotency_key' => 'foreign-'.bin2hex(random_bytes(4)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->branchToken($client)],
        );
        self::assertResponseStatusCodeSame(404);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return array<string,mixed> */
    private function body(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function branchToken(KernelBrowser $client): string
    {
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        return $this->csrfToken($client, 'branch_access');
    }

    private function api(Branch $branch, string $suffix = ''): string
    {
        $base = '/api/v1/branches/'.$branch->id().'/production';

        return $suffix === '' ? $base : $base.'/'.$suffix;
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   branch: Branch,
     *   owner: User,
     *   source: InventorySource,
     *   variant: ProductVariant,
     *   material: Material
     * }
     */
    private function fixture(EntityManagerInterface $em): array
    {
        $catalog = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $catalog);
        $catalog->seed();

        $salt = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Ops '.$salt, 'ops-'.$salt);
        $legal = new LegalEntity($tenant, 'Ops '.$salt.' SAS', null, true);
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal-'.$salt,
            $legal,
            true,
        );
        $owner = new User('owner-'.$salt.'@example.test', 'Owner ops');
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
        );
        $source = new InventorySource(
            $tenant,
            $legal,
            'Principal',
            'principal-'.$salt,
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        $product = new Product($tenant, 'Producto '.$salt, 'producto-'.$salt);
        $variant = new ProductVariant(
            $tenant,
            $product,
            'SKU-'.$salt,
            'Única',
        );
        $material = new Material(
            $tenant,
            'MAT-'.$salt,
            'Materia '.$salt,
            UnitOfMeasure::from('kg'),
        );
        $this->persist(
            $em,
            [
                $tenant,
                $legal,
                $branch,
                $owner,
                $membership,
                $source,
                $product,
                $variant,
                $material,
            ],
        );
        $this->subscribe($em, $tenant);

        return compact(
            'tenant',
            'branch',
            'owner',
            'source',
            'variant',
            'material',
        );
    }

    private function subscribe(
        EntityManagerInterface $em,
        Tenant $tenant,
    ): void {
        $plan = $em->getRepository(Plan::class)->findOneBy(['key' => 'business']);
        $vertical = $em->getRepository(Vertical::class)->findOneBy([
            'key' => 'commerce',
        ]);
        $addOn = $em->getRepository(AddOn::class)->findOneBy([
            'key' => 'production-lite',
        ]);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertInstanceOf(Vertical::class, $vertical);
        self::assertInstanceOf(AddOn::class, $addOn);

        $version = $em->getRepository(PlanVersion::class)->findOneBy([
            'plan' => $plan,
            'version' => 1,
        ]);
        self::assertInstanceOf(PlanVersion::class, $version);

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subscription = Subscription::fromLifecycle(
            new SubscriptionLifecycle(
                $tenant->id(),
                $version,
                SubscriptionState::Active,
                $at,
            ),
            $at,
        );
        $limits = array_filter(
            $version->limits(),
            static fn (mixed $value): bool => is_int($value),
        );
        $em->persist($subscription);
        $em->persist(new SubscriptionConfiguration(
            $subscription,
            $vertical,
            $limits,
            [$addOn],
            $at,
        ));
        $em->flush();
    }

    /** @param list<object> $entities */
    private function persist(
        EntityManagerInterface $em,
        array $entities,
    ): void {
        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    /** @return array<string,mixed> */
    private function postBom(
        KernelBrowser $client,
        string $url,
        string $token,
        ProductVariant $variant,
        Material $material,
        string $quantity,
        string $unit,
    ): array {
        $client->jsonRequest(
            'POST',
            $url,
            $this->bomRequest($variant, $material, $quantity, $unit),
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseStatusCodeSame(201);

        return $this->body($client)['bom'];
    }

    /** @return array<string,mixed> */
    private function bomRequest(
        ProductVariant $variant,
        Material $material,
        string $quantity,
        string $unit = 'kg',
    ): array {
        return [
            'variant_id' => $variant->id(),
            'components' => [[
                'material_id' => $material->id(),
                'quantity' => $quantity,
                'unit' => $unit,
            ]],
        ];
    }
}
