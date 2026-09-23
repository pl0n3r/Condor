<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryControllerTest extends WebTestCase
{
    public function testOwnerCanAdjustTransferAndReadInventory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, $legalEntity, $branch, $owner, $variant] =
            $this->tenantWithOwner($entityManager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/inventory';

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'Principal', 'slug' => 'principal', 'type' => 'branch'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $sourceA = $this->json($client)['source']['id'];

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'TikTok', 'slug' => 'tiktok', 'type' => 'logical'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $sourceB = $this->json($client)['source']['id'];

        $adjustmentKey = 'adjust-http-'.bin2hex(random_bytes(5));
        $client->jsonRequest(
            'POST',
            $base.'/adjustments',
            [
                'source_id' => $sourceA,
                'variant_id' => $variant->id(),
                'delta' => 10,
                'reason' => 'Inventario inicial',
                'idempotency_key' => $adjustmentKey,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest(
            'POST',
            $base.'/adjustments',
            [
                'source_id' => $sourceA,
                'variant_id' => $variant->id(),
                'delta' => 10,
                'reason' => 'Inventario inicial',
                'idempotency_key' => $adjustmentKey,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);

        $transferKey = 'transfer-http-'.bin2hex(random_bytes(5));
        $client->jsonRequest(
            'POST',
            $base.'/transfers',
            [
                'source_from_id' => $sourceA,
                'source_to_id' => $sourceB,
                'variant_id' => $variant->id(),
                'quantity' => 4,
                'idempotency_key' => $transferKey,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest(
            'POST',
            $base.'/transfers',
            [
                'source_from_id' => $sourceA,
                'source_to_id' => $sourceB,
                'variant_id' => $variant->id(),
                'quantity' => 4,
                'idempotency_key' => $transferKey,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);

        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $snapshot = $this->json($client);

        self::assertSame($legalEntity->id(), $snapshot['legal_entity']['id']);
        self::assertCount(2, $snapshot['sources']);
        self::assertCount(2, $snapshot['balances']);
        self::assertCount(3, $snapshot['movements']);

        $quantities = [];
        foreach ($snapshot['balances'] as $balance) {
            $quantities[$balance['source_id']] = $balance['quantity'];
        }
        self::assertSame(6, $quantities[$sourceA]);
        self::assertSame(4, $quantities[$sourceB]);

        $storedSources = $entityManager
            ->getRepository(InventorySource::class)
            ->findBy(['tenant' => $tenant, 'legalEntity' => $legalEntity]);
        self::assertCount(2, $storedSources);
        self::assertSame(
            1,
            (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM condor_audit_event '
                .'WHERE tenant_id = ? AND action = ? AND entity_type = ?',
                [$tenant->id(), 'inventory.adjusted', InventoryMovement::class],
            ),
        );
        self::assertSame(
            1,
            (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM condor_audit_event '
                .'WHERE tenant_id = ? AND action = ? AND entity_type = ?',
                [
                    $tenant->id(),
                    'inventory.transferred',
                    \App\Domain\Inventory\Entity\InventoryTransfer::class,
                ],
            ),
        );
    }

    public function testBranchSourceCanBeReactivatedAfterDeactivation(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [, , $branch, $owner] = $this->tenantWithOwner($entityManager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/inventory';

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'Principal', 'slug' => 'principal', 'type' => 'branch'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $sourceId = $this->json($client)['source']['id'];

        $client->request(
            'DELETE',
            $base.'/sources/'.$sourceId,
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            [
                'name' => 'Principal reactivada',
                'slug' => 'principal-reactivada',
                'type' => 'branch',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);
        $reactivated = $this->json($client)['source'];
        self::assertSame($sourceId, $reactivated['id']);
        self::assertSame('Principal reactivada', $reactivated['name']);

        $source = $entityManager->getRepository(InventorySource::class)->find($sourceId);
        self::assertInstanceOf(InventorySource::class, $source);
        self::assertTrue($source->isActive());
    }

    public function testOwnerCanUpdateAndDeactivateEmptySource(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [, , $branch, $owner] = $this->tenantWithOwner($entityManager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/inventory';

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'Temporal', 'slug' => 'temporal', 'type' => 'logical'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $sourceId = $this->json($client)['source']['id'];

        $client->jsonRequest(
            'PATCH',
            $base.'/sources/'.$sourceId,
            ['name' => 'Renombrada', 'slug' => 'renombrada'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        $updated = $this->json($client)['source'];
        self::assertSame('Renombrada', $updated['name']);
        self::assertSame('renombrada', $updated['slug']);

        $client->request(
            'DELETE',
            $base.'/sources/'.$sourceId,
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $source = $entityManager
            ->getRepository(InventorySource::class)
            ->find($sourceId);
        self::assertInstanceOf(InventorySource::class, $source);
        self::assertFalse($source->isActive());

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'Reactivada', 'slug' => 'renombrada', 'type' => 'logical'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame($sourceId, $this->json($client)['source']['id']);

        $entityManager->refresh($source);
        self::assertTrue($source->isActive());
        self::assertSame('Reactivada', $source->name());
    }

    public function testSourceWithStockCannotBeDeactivated(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [, , $branch, $owner, $variant] =
            $this->tenantWithOwner($entityManager);

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branch->id().'/inventory';

        $client->jsonRequest(
            'POST',
            $base.'/sources',
            ['name' => 'Con stock', 'slug' => 'con-stock', 'type' => 'logical'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $sourceId = $this->json($client)['source']['id'];

        $client->jsonRequest(
            'POST',
            $base.'/adjustments',
            [
                'source_id' => $sourceId,
                'variant_id' => $variant->id(),
                'delta' => 1,
                'reason' => 'Stock protegido',
                'idempotency_key' => 'protect-'.bin2hex(random_bytes(5)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->request(
            'DELETE',
            $base.'/sources/'.$sourceId,
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(409);

        $source = $entityManager
            ->getRepository(InventorySource::class)
            ->find($sourceId);
        self::assertInstanceOf(InventorySource::class, $source);
        self::assertTrue($source->isActive());
    }

    public function testDelegatedViewerCannotAdjustInventory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, $legalEntity, $branch, , $variant] =
            $this->tenantWithOwner($entityManager);

        $user = new User(
            'inventory-viewer-'.bin2hex(random_bytes(4)).'@example.test',
            'Viewer',
        );
        $membership = new Membership($tenant, $user, 'ADMIN');
        $role = new Role($tenant, 'Inventario lectura', ['inventory.view']);
        $assignment = new BranchRoleAssignment($membership, $branch, $role);
        $source = new InventorySource(
            $tenant,
            $legalEntity,
            'Principal',
            'principal-viewer',
            InventorySource::TYPE_BRANCH,
            $branch,
        );
        foreach ([$user, $membership, $role, $assignment, $source] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($user);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/inventory/adjustments',
            [
                'source_id' => $source->id(),
                'variant_id' => $variant->id(),
                'delta' => 1,
                'reason' => 'No autorizado',
                'idempotency_key' => 'denied-'.bin2hex(random_bytes(4)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testDelegatedEditorCannotOperateOtherBranchOrLogicalSources(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, $legalEntity, $branchA, , $variant] =
            $this->tenantWithOwner($entityManager);

        $branchB = new Branch(
            $tenant,
            'Secundaria',
            'secundaria-'.bin2hex(random_bytes(3)),
            $legalEntity,
        );
        $sourceA = new InventorySource(
            $tenant,
            $legalEntity,
            'Fuente A',
            'fuente-a-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_BRANCH,
            $branchA,
        );
        $sourceB = new InventorySource(
            $tenant,
            $legalEntity,
            'Fuente B',
            'fuente-b-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_BRANCH,
            $branchB,
        );
        $logicalSource = new InventorySource(
            $tenant,
            $legalEntity,
            'Canal lógico',
            'canal-logico-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $balanceA = new InventoryBalance(
            $tenant,
            $sourceA,
            $variant,
            7,
        );

        $user = new User(
            'inventory-editor-'.bin2hex(random_bytes(4)).'@example.test',
            'Editor sede A',
        );
        $membership = new Membership($tenant, $user, 'ADMIN');
        $role = new Role(
            $tenant,
            'Inventario sede A',
            ['inventory.view', 'inventory.update'],
        );
        $assignment = new BranchRoleAssignment(
            $membership,
            $branchA,
            $role,
        );

        foreach (
            [
                $branchB,
                $sourceA,
                $sourceB,
                $logicalSource,
                $balanceA,
                $user,
                $membership,
                $role,
                $assignment,
            ] as $entity
        ) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($user);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branchA->id().'/inventory';

        $client->jsonRequest(
            'POST',
            $base.'/adjustments',
            [
                'source_id' => $sourceB->id(),
                'variant_id' => $variant->id(),
                'delta' => 1,
                'reason' => 'Intento fuera de sede',
                'idempotency_key' => 'branch-denied-'.bin2hex(random_bytes(5)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'POST',
            $base.'/transfers',
            [
                'source_from_id' => $sourceA->id(),
                'source_to_id' => $sourceB->id(),
                'variant_id' => $variant->id(),
                'quantity' => 1,
                'idempotency_key' => 'branch-transfer-'.bin2hex(random_bytes(5)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'POST',
            $base.'/transfers',
            [
                'source_from_id' => $sourceA->id(),
                'source_to_id' => $logicalSource->id(),
                'variant_id' => $variant->id(),
                'quantity' => 1,
                'idempotency_key' => 'logical-transfer-'.bin2hex(random_bytes(5)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'PATCH',
            $base.'/sources/'.$sourceB->id(),
            ['name' => 'Fuente B alterada', 'slug' => $sourceB->slug()],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $connection = $entityManager->getConnection();
        self::assertSame(
            7,
            (int) $connection->fetchOne(
                'SELECT quantity FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id = ? AND variant_id = ?',
                [$tenant->id(), $sourceA->id(), $variant->id()],
            ),
        );
        self::assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM condor_inventory_balance '
                .'WHERE tenant_id = ? AND source_id IN (?, ?)',
                [
                    $tenant->id(),
                    $sourceB->id(),
                    $logicalSource->id(),
                ],
            ),
        );
        self::assertSame(
            'Fuente B',
            (string) $connection->fetchOne(
                'SELECT name FROM condor_inventory_source WHERE id = ?',
                [$sourceB->id()],
            ),
        );
    }

    public function testCrossEntitySourceIsNotResolvedFromActiveBranch(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, , $branch, $owner, $variant] =
            $this->tenantWithOwner($entityManager);

        $otherLegalEntity = new LegalEntity(
            $tenant,
            'Segunda razón social',
            null,
        );
        $otherBranch = new Branch(
            $tenant,
            'Segunda sede',
            'segunda-'.bin2hex(random_bytes(3)),
            $otherLegalEntity,
        );
        $otherSource = new InventorySource(
            $tenant,
            $otherLegalEntity,
            'Bodega segunda',
            'bodega-segunda-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_BRANCH,
            $otherBranch,
        );
        foreach ([$otherLegalEntity, $otherBranch, $otherSource] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/inventory/adjustments',
            [
                'source_id' => $otherSource->id(),
                'variant_id' => $variant->id(),
                'delta' => 1,
                'reason' => 'Cruce inválido',
                'idempotency_key' => 'cross-'.bin2hex(random_bytes(4)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testSourceTypeIsNormalizedBeforeAuthorization(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, , $branch, $owner] = $this->tenantWithOwner($entityManager);

        $user = new User(
            'inventory-creator-'.bin2hex(random_bytes(4)).'@example.test',
            'Creator',
        );
        $membership = new Membership($tenant, $user, 'ADMIN');
        $role = new Role($tenant, 'Inventario crear', ['inventory.create']);
        $assignment = new BranchRoleAssignment($membership, $branch, $role);
        foreach ([$user, $membership, $role, $assignment] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($user);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/inventory/sources',
            ['name' => 'Lógica', 'slug' => 'logica', 'type' => ' Logical '],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/inventory/sources',
            ['name' => 'Inválida', 'slug' => 'invalida', 'type' => 'warehouse'],
            ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testInventoryRejectsCrossTenantIdentifiers(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenantA, $legalA, $branchA, $ownerA, $variantA] =
            $this->tenantWithOwner($entityManager);
        [$tenantB, $legalB, $branchB, , $variantB] =
            $this->tenantWithOwner($entityManager);

        $localA = new InventorySource(
            $tenantA,
            $legalA,
            'Local A',
            'local-a-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $localB = new InventorySource(
            $tenantA,
            $legalA,
            'Local B',
            'local-b-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $foreignA = new InventorySource(
            $tenantB,
            $legalB,
            'Ajena A',
            'ajena-a-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $foreignB = new InventorySource(
            $tenantB,
            $legalB,
            'Ajena B',
            'ajena-b-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        foreach ([$localA, $localB, $foreignA, $foreignB] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($ownerA);
        $csrf = $this->csrf($client);
        $base = '/api/v1/branches/'.$branchA->id().'/inventory';

        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/inventory');
        self::assertResponseStatusCodeSame(404);

        foreach (
            [
                [
                    'source_id' => $foreignA->id(),
                    'variant_id' => $variantA->id(),
                ],
                [
                    'source_id' => $localA->id(),
                    'variant_id' => $variantB->id(),
                ],
            ] as $foreignAdjustment
        ) {
            $client->jsonRequest(
                'POST',
                $base.'/adjustments',
                $foreignAdjustment + [
                    'delta' => 1,
                    'reason' => 'Cruce tenant',
                    'idempotency_key' => 'cross-adjust-'.bin2hex(random_bytes(5)),
                ],
                ['HTTP_X_CSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(404);
        }

        foreach (
            [
                [
                    'source_from_id' => $foreignA->id(),
                    'source_to_id' => $localB->id(),
                    'variant_id' => $variantA->id(),
                ],
                [
                    'source_from_id' => $localA->id(),
                    'source_to_id' => $foreignB->id(),
                    'variant_id' => $variantA->id(),
                ],
                [
                    'source_from_id' => $localA->id(),
                    'source_to_id' => $localB->id(),
                    'variant_id' => $variantB->id(),
                ],
            ] as $foreignTransfer
        ) {
            $client->jsonRequest(
                'POST',
                $base.'/transfers',
                $foreignTransfer + [
                    'quantity' => 1,
                    'idempotency_key' => 'cross-transfer-'.bin2hex(random_bytes(5)),
                ],
                ['HTTP_X_CSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testDatabaseRejectsBranchSourceWithMismatchedLegalEntity(): void
    {
        $entityManager = $this->entityManager();
        [$tenant, $legalEntity] = $this->tenantWithOwner($entityManager);

        $otherLegalEntity = new LegalEntity(
            $tenant,
            'Entidad alterna '.bin2hex(random_bytes(3)),
            null,
        );
        $otherBranch = new Branch(
            $tenant,
            'Sede alterna',
            'sede-alterna-'.bin2hex(random_bytes(3)),
            $otherLegalEntity,
        );
        $entityManager->persist($otherLegalEntity);
        $entityManager->persist($otherBranch);
        $entityManager->flush();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $entityManager->getConnection()->executeStatement(
            'INSERT INTO condor_inventory_source '
            .'(id, tenant_id, legal_entity_id, branch_id, name, slug, type, active, created_at, updated_at) '
            ."VALUES (?, ?, ?, ?, 'Cruce inválido', ?, 'branch', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [
                UlidFactory::new(),
                $tenant->id(),
                $legalEntity->id(),
                $otherBranch->id(),
                'cruce-'.bin2hex(random_bytes(4)),
            ],
        );
    }

    public function testBranchWithoutLegalEntityCannotOperateInventory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();

        $suffix = bin2hex(random_bytes(4));
        $tenant = new Tenant('Legacy '.$suffix, 'legacy-'.$suffix);
        $branch = new Branch($tenant, 'Sin entidad', 'sin-entidad', null, true);
        $owner = new User(
            'legacy-'.$suffix.'@example.test',
            'Owner legacy',
        );
        $membership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        foreach ([$tenant, $branch, $owner, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/inventory',
        );

        self::assertResponseStatusCodeSame(409);
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
     * @return array{Tenant, LegalEntity, Branch, User, ProductVariant}
     */
    private function tenantWithOwner(
        EntityManagerInterface $entityManager,
    ): array {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $legalEntity = new LegalEntity(
            $tenant,
            'Empresa '.$suffix.' SAS',
            null,
            true,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal',
            $legalEntity,
            true,
        );
        $owner = new User(
            'owner-'.$suffix.'@example.test',
            'Owner',
        );
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
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

        foreach (
            [
                $tenant,
                $legalEntity,
                $branch,
                $owner,
                $membership,
                $product,
                $variant,
            ] as $entity
        ) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [$tenant, $legalEntity, $branch, $owner, $variant];
    }
}
