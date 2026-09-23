<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
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

        $client->jsonRequest(
            'POST',
            $base.'/adjustments',
            [
                'source_id' => $sourceA,
                'variant_id' => $variant->id(),
                'delta' => 10,
                'reason' => 'Inventario inicial',
                'idempotency_key' => 'adjust-http-'.bin2hex(random_bytes(5)),
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
                'idempotency_key' => 'transfer-http-'.bin2hex(random_bytes(5)),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

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
