<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
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
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\MaterialInventoryBalance;
use App\Domain\Production\Entity\MaterialInventoryMovement;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductionMaterialsControllerTest extends WebTestCase
{
    public function testSnapshotListsTenantMaterialsBranchBalancesAndRecentMovementsOnlyWhenEntitled(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        [$tenant, , $branch, $owner, $source] =
            $this->tenantWithOwner($manager, true, true);
        self::assertInstanceOf(InventorySource::class, $source);

        $material = new Material(
            $tenant,
            'TELA-RAW',
            'Tela cruda',
            UnitOfMeasure::from('kg'),
        );
        $balance = new MaterialInventoryBalance(
            $tenant,
            $source,
            $material,
            '5',
        );
        $movement = new MaterialInventoryMovement(
            $tenant,
            $source,
            $material,
            MaterialInventoryMovement::TYPE_ADJUSTMENT_IN,
            '5',
            '5',
            null,
            'snapshot-'.bin2hex(random_bytes(4)),
            ['reason' => 'fixture'],
        );
        foreach ([$material, $balance, $movement] as $entity) {
            $manager->persist($entity);
        }

        [$otherTenant] = $this->tenantWithOwner($manager, true, true);
        $manager->persist(new Material(
            $otherTenant,
            'FOREIGN',
            'Material ajeno',
            UnitOfMeasure::from('unit'),
        ));
        $manager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/production/materials',
        );

        self::assertResponseIsSuccessful();
        $payload = $this->json($client);
        self::assertSame($branch->id(), $payload['branch']['id']);
        self::assertSame($source->id(), $payload['source']['id']);
        self::assertContains('kg', $payload['units']);
        self::assertCount(1, $payload['materials']);
        self::assertSame($material->id(), $payload['materials'][0]['id']);
        self::assertSame('TELA-RAW', $payload['materials'][0]['code']);
        self::assertCount(1, $payload['balances']);
        self::assertSame('5', $payload['balances'][0]['quantity']);
        self::assertCount(1, $payload['movements']);
        self::assertSame(
            $material->id(),
            $payload['movements'][0]['material_id'],
        );
    }

    public function testMutationsRequireInventoryPermissionsCsrfAndUseDomainServices(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();
        [$tenant, , $branch, $owner] =
            $this->tenantWithOwner($manager, true, true);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/production/materials';

        $client->jsonRequest(
            'POST',
            $base,
            ['code' => 'raw-1', 'name' => 'Materia prima', 'unit' => 'kg'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $material = $this->json($client)['material'];
        self::assertSame('RAW-1', $material['code']);
        self::assertSame('kg', $material['unit']);

        $client->jsonRequest(
            'PATCH',
            $base.'/'.$material['id'],
            ['code' => 'raw-2', 'name' => 'Materia actualizada', 'unit' => 'g'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertSame('RAW-2', $this->json($client)['material']['code']);

        $key = 'material-http-'.bin2hex(random_bytes(5));
        $adjustment = [
            'delta' => '2.5',
            'reason' => 'Carga inicial',
            'idempotency_key' => $key,
        ];
        $client->jsonRequest(
            'POST',
            $base.'/'.$material['id'].'/adjustments',
            $adjustment,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2.5', $this->json($client)['movement']['balance_after']);

        $client->jsonRequest(
            'POST',
            $base.'/'.$material['id'].'/adjustments',
            $adjustment,
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            1,
            $manager->getRepository(MaterialInventoryMovement::class)->count([
                'tenant' => $tenant,
                'idempotencyKey' => $key,
            ]),
        );

        $client->request(
            'DELETE',
            $base.'/'.$material['id'],
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json($client)['material']['active']);

        $client->jsonRequest(
            'POST',
            $base,
            ['code' => 'NO-CSRF', 'name' => 'Denegada', 'unit' => 'unit'],
        );
        self::assertResponseStatusCodeSame(403);

        $viewer = new User(
            'production-viewer-'.bin2hex(random_bytes(4)).'@example.test',
            'Viewer producción',
        );
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Producción lectura', ['inventory.view']);
        $assignment = new BranchRoleAssignment($membership, $branch, $role);
        foreach ([$viewer, $membership, $role, $assignment] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $client->loginUser($viewer);
        $client->jsonRequest(
            'POST',
            $base,
            ['code' => 'DENIED', 'name' => 'Denegada', 'unit' => 'unit'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testCrossTenantMissingEntitlementForeignMaterialOrMissingBranchSourceFailsClosed(): void
    {
        $client = static::createClient();
        $manager = $this->entityManager();

        [$tenantA, , $branchA, $ownerA] =
            $this->tenantWithOwner($manager, true, true);
        $tenantB = $this->tenantWithOwner(
            $manager,
            true,
            true,
        )[0];
        $foreign = new Material(
            $tenantB,
            'FOREIGN-RAW',
            'Materia ajena',
            UnitOfMeasure::from('unit'),
        );
        $manager->persist($foreign);
        $manager->flush();

        $client->loginUser($ownerA);
        $client->jsonRequest(
            'PATCH',
            '/api/v1/branches/'.$branchA->id().'/production/materials/'.$foreign->id(),
            ['code' => 'HACK', 'name' => 'Cruce', 'unit' => 'unit'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(404);

        [, , $branchNoEntitlement, $ownerNoEntitlement] =
            $this->tenantWithOwner($manager, false, true);
        $client->loginUser($ownerNoEntitlement);
        $client->request(
            'GET',
            '/api/v1/branches/'.$branchNoEntitlement->id().'/production/materials',
        );
        self::assertResponseStatusCodeSame(422);

        [, , $branchNoSource, $ownerNoSource] =
            $this->tenantWithOwner($manager, true, false);
        $client->loginUser($ownerNoSource);
        $client->request(
            'GET',
            '/api/v1/branches/'.$branchNoSource->id().'/production/materials',
        );
        self::assertResponseStatusCodeSame(404);

        self::assertSame(
            'FOREIGN-RAW',
            $foreign->code(),
        );
        self::assertSame($tenantA->id(), $branchA->tenant()->id());
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
     *   InventorySource|null
     * }
     */
    private function tenantWithOwner(
        EntityManagerInterface $manager,
        bool $productionLite,
        bool $withSource,
    ): array {
        $seeder = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Producción '.$suffix, 'produccion-'.$suffix);
        $legalEntity = new LegalEntity(
            $tenant,
            'Producción '.$suffix.' SAS',
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
            'owner-production-'.$suffix.'@example.test',
            'Owner producción',
        );
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
        );
        $source = $withSource
            ? new InventorySource(
                $tenant,
                $legalEntity,
                'Principal',
                'principal-'.$suffix,
                InventorySource::TYPE_BRANCH,
                $branch,
            )
            : null;

        foreach (
            array_filter(
                [$tenant, $legalEntity, $branch, $owner, $membership, $source],
                static fn (mixed $entity): bool => is_object($entity),
            ) as $entity
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
            $productionLite ? [$production] : [],
            $now,
        );
        $manager->persist($subscription);
        $manager->persist($configuration);
        $manager->flush();

        return [$tenant, $legalEntity, $branch, $owner, $source];
    }
}
