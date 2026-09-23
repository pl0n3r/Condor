<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\BrowserCsrfToken;
use App\Shared\Id\UlidFactory;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Commerce\Entity\VariantPrice;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontAdminControllerTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testOwnerCanEditIdentityAndSeeItOnPublicSlug(): void
    {
        [$client, $entityManager, $tenant, $owner] = $this->tenantBrowser();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin/storefront');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        $client->request('POST', '/admin/storefront', [
            '_token' => $token,
            'headline' => 'Ferretería del barrio',
            'description' => 'Herramientas y materiales disponibles en Pereira.',
        ]);

        self::assertResponseRedirects('/admin/storefront?saved=profile');

        $profile = $entityManager->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(StorefrontProfile::class, $profile);
        self::assertSame('Ferretería del barrio', $profile->headline());

        $client->catchExceptions(false);
        $client->request('GET', '/'.$tenant->slug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ferretería del barrio');
        self::assertSelectorTextContains(
            '.storefront-hero p',
            'Herramientas y materiales disponibles en Pereira.',
        );
        self::assertSelectorExists(
            'link[rel="canonical"][href="https://www.condorapp.com.co/'.
            $tenant->slug().'"]',
        );
    }

    public function testOwnerPostRejectsMissingOrInvalidCsrfWithoutChangingProfile(): void
    {
        [$client, $entityManager, $tenant, $owner] = $this->tenantBrowser();
        $profile = new StorefrontProfile(
            $tenant,
            'Titular original',
            'Descripción original.',
        );
        $entityManager->persist($profile);
        $entityManager->flush();

        $client->loginUser($owner);

        foreach (['', 'token-invalido'] as $token) {
            $client->request('POST', '/admin/storefront', [
                '_token' => $token,
                'headline' => 'Titular alterado',
                'description' => 'No debe persistirse.',
            ]);
            self::assertResponseStatusCodeSame(403);
        }

        $stored = $this->storedProfile($tenant);
        self::assertSame('Titular original', $stored->headline());
        self::assertSame('Descripción original.', $stored->description());
    }

    public function testVerifiedPrimaryDomainServesSameTenantAndCanonical(): void
    {
        [$client, $entityManager, $tenant] = $this->tenantBrowser();
        $host = 'tienda-'.strtolower(bin2hex(random_bytes(4))).'.example.test';

        $entityManager->persist(new StorefrontProfile(
            $tenant,
            'Catálogo de prueba',
            'El mismo storefront servido desde un dominio verificado.',
        ));
        $this->registerVerifiedPrimaryDomain($entityManager, $tenant, $host);

        $client->request('GET', '/', server: ['HTTP_HOST' => $host]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catálogo de prueba');
        self::assertSelectorExists(
            'link[rel="canonical"][href="https://'.$host.'/"]',
        );
    }

    public function testTenantCannotPersistTwoVerifiedPrimaryDomains(): void
    {
        $entityManager = $this->entityManager();

        [$tenant] = $this->tenantOwner($entityManager);
        $entityManager->persist(new TenantDomain(
            $tenant,
            'primario-a-'.strtolower(bin2hex(random_bytes(4))).'.example.test',
            true,
            true,
        ));
        $entityManager->flush();

        $entityManager->persist(new TenantDomain(
            $tenant,
            'primario-b-'.strtolower(bin2hex(random_bytes(4))).'.example.test',
            true,
            true,
        ));

        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    public function testLegacyWriterCannotBypassPrimaryDomainUniqueness(): void
    {
        $entityManager = $this->entityManager();
        [$tenant] = $this->tenantOwner($entityManager);
        $suffix = strtolower(bin2hex(random_bytes(4)));

        $entityManager->persist(new TenantDomain(
            $tenant,
            'primary-'.$suffix.'.example.test',
            true,
            true,
        ));
        $entityManager->flush();

        // Simula una instancia previa a V 0.1.13 que no conoce la columna generada.
        $connection = $entityManager->getConnection();
        $computed = $connection->fetchOne(
            'SELECT primary_verified_tenant_id FROM condor_tenant_domain '
            .'WHERE hostname = ?',
            ['primary-'.$suffix.'.example.test'],
        );
        self::assertSame($tenant->id(), $computed);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('condor_tenant_domain', [
            'id' => UlidFactory::new(),
            'tenant_id' => $tenant->id(),
            'hostname' => 'legacy-primary-'.$suffix.'.example.test',
            'is_primary' => 1,
            'is_verified' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testLegacyWriterCannotPromoteSecondVerifiedDomain(): void
    {
        $entityManager = $this->entityManager();
        [$tenant] = $this->tenantOwner($entityManager);
        $suffix = strtolower(bin2hex(random_bytes(4)));

        $entityManager->persist(new TenantDomain(
            $tenant,
            'primary-'.$suffix.'.example.test',
            true,
            true,
        ));
        $entityManager->persist(new TenantDomain(
            $tenant,
            'secondary-'.$suffix.'.example.test',
            false,
            true,
        ));
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        self::assertNull($connection->fetchOne(
            'SELECT primary_verified_tenant_id FROM condor_tenant_domain '
            .'WHERE hostname = ?',
            ['secondary-'.$suffix.'.example.test'],
        ) ?: null);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->executeStatement(
            'UPDATE condor_tenant_domain SET is_primary = 1 WHERE hostname = ?',
            ['secondary-'.$suffix.'.example.test'],
        );
    }

    public function testUnverifiedCustomHostFailsClosed(): void
    {
        [$client, $entityManager, $tenant] = $this->tenantBrowser();
        $host = 'pendiente-'.strtolower(bin2hex(random_bytes(4))).'.example.test';

        $entityManager->persist(new TenantDomain(
            $tenant,
            $host,
            true,
            false,
        ));
        $entityManager->flush();

        $client->request('GET', '/', server: ['HTTP_HOST' => $host]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownCustomHostFailsClosed(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/',
            server: ['HTTP_HOST' => 'desconocido-'.strtolower(bin2hex(random_bytes(4))).'.example.test'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifiedDomainDoesNotLeakAnotherTenantProfile(): void
    {
        [$client, $entityManager, $tenant] = $this->tenantBrowser();
        [$otherTenant] = $this->tenantOwner($entityManager);
        $host = 'aislado-'.strtolower(bin2hex(random_bytes(4))).'.example.test';

        $entityManager->persist(new StorefrontProfile(
            $tenant,
            'Empresa visible',
            'Contenido exclusivo del tenant resuelto.',
        ));
        $entityManager->persist(new StorefrontProfile(
            $otherTenant,
            'MARCADOR-OTRO-TENANT',
            'Este contenido nunca debe salir por el dominio ajeno.',
        ));
        $this->registerVerifiedPrimaryDomain($entityManager, $tenant, $host);

        $client->request('GET', '/', server: ['HTTP_HOST' => $host]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Empresa visible');
        self::assertStringNotContainsString(
            'MARCADOR-OTRO-TENANT',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testDelegatedViewPermissionStaysReadOnly(): void
    {
        [$client, $entityManager, $tenant] = $this->tenantBrowser();
        $branch = $entityManager->getRepository(Branch::class)->findOneBy([
            'tenant' => $tenant,
        ]);
        self::assertInstanceOf(Branch::class, $branch);

        $user = new User(
            'storefront-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'Gestor del sitio',
        );
        $membership = new Membership($tenant, $user, 'ADMIN');
        $entityManager->persist($user);
        $entityManager->persist($membership);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/storefront');
        self::assertResponseStatusCodeSame(403);

        $role = new Role(
            $tenant,
            'Gestión de sitio por sede',
            ['site.view'],
        );
        $entityManager->persist($role);
        $entityManager->persist(new BranchRoleAssignment(
            $membership,
            $branch,
            $role,
        ));
        $profile = new StorefrontProfile(
            $tenant,
            'Identidad protegida',
            'El perfil global no hereda permisos de una sede.',
        );
        $entityManager->persist($profile);
        $entityManager->flush();

        $client->request('GET', '/admin/storefront');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.storefront-admin-form');
        self::assertSelectorTextContains(
            '.storefront-admin-card',
            'permiso permite consultar',
        );

        $token = $this->csrfToken($client, 'storefront_profile');

        $client->request('POST', '/admin/storefront', [
            '_token' => $token,
            'headline' => 'Intento delegado',
            'description' => 'No debe persistirse.',
        ]);

        self::assertResponseStatusCodeSame(403);
        $stored = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(StorefrontProfile::class, $stored);
        self::assertSame('Identidad protegida', $stored->headline());
    }

    public function testDelegatedUpdatePermissionCanEditStorefront(): void
    {
        [$client, $entityManager, $tenant] = $this->tenantBrowser();
        $branch = $entityManager->getRepository(Branch::class)->findOneBy([
            'tenant' => $tenant,
        ]);
        self::assertInstanceOf(Branch::class, $branch);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $user = new User(
            'site-update-'.$suffix.'@example.test',
            'Editor del sitio',
        );
        $membership = new Membership($tenant, $user, 'ADMIN');
        $role = new Role($tenant, 'Editor sitio', ['site.update']);
        foreach ([$user, $membership, $role] as $record) {
            $entityManager->persist($record);
        }
        $entityManager->persist(new BranchRoleAssignment(
            $membership,
            $branch,
            $role,
        ));
        $entityManager->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/storefront');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.storefront-admin-form');
        $token = $crawler
            ->filter('form.storefront-admin-form input[name="_token"]')
            ->first()
            ->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/storefront', [
            '_token' => $token,
            '_action' => 'profile',
            'headline' => 'Editado por permiso',
            'description' => 'Cambio autorizado por site.update.',
        ]);

        self::assertResponseRedirects('/admin/storefront?saved=profile');
        $stored = $entityManager->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(StorefrontProfile::class, $stored);
        self::assertSame('Editado por permiso', $stored->headline());
    }

    public function testOwnerConfiguresAuditedChannelAndPublishesCatalog(): void
    {
        [$client, $entityManager, $tenant, $owner] = $this->tenantBrowser();
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $legal = new LegalEntity(
            $tenant,
            'Empresa web '.$suffix.' SAS',
            null,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legal,
            'Bodega web',
            'bodega-web-'.$suffix,
            InventorySource::TYPE_LOGICAL,
        );
        $list = new PriceList(
            $tenant,
            'Lista web',
            'lista-web-'.$suffix,
        );
        $product = new Product(
            $tenant,
            'Body público',
            'body-publico-'.$suffix,
            'Prenda visible en el storefront.',
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'WEB-'.strtoupper($suffix),
            'Talla única',
        );
        $price = new VariantPrice(
            $tenant,
            $list,
            $variant,
            125000,
        );
        $balance = new InventoryBalance(
            $tenant,
            $source,
            $variant,
            2,
        );
        foreach ([
            $legal,
            $source,
            $list,
            $product,
            $variant,
            $price,
            $balance,
        ] as $record) {
            $entityManager->persist($record);
        }
        $entityManager->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin/storefront');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.channel-admin-form');

        $token = $crawler
            ->filter('form.channel-admin-form input[name="_token"]')
            ->attr('value');
        self::assertIsString($token);

        $client->request('POST', '/admin/storefront', [
            '_token' => $token,
            '_action' => 'channel',
            'name' => 'Tienda web',
            'slug' => 'tienda-web',
            'inventory_source_id' => $source->id(),
            'price_list_id' => $list->id(),
            'active' => '1',
        ]);

        self::assertResponseRedirects('/admin/storefront?saved=channel');

        $channel = $entityManager->getRepository(SalesChannel::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(SalesChannel::class, $channel);
        self::assertSame($source->id(), $channel->inventorySource()->id());
        self::assertSame($list->id(), $channel->priceList()->id());

        $auditCount = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM condor_audit_event '
            .'WHERE tenant_id = ? AND action = ? AND entity_id = ?',
            [$tenant->id(), 'sales_channel.created', $channel->id()],
        );
        self::assertSame(1, $auditCount);

        $client->request('GET', '/'.$tenant->slug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            '.storefront-catalog',
            'Body público',
        );
        self::assertSelectorTextContains(
            '.storefront-catalog',
            'COP 1.250',
        );
        self::assertSelectorTextContains(
            '.storefront-catalog',
            'Disponible',
        );
    }

    public function testDatabaseRejectsCrossEntityChannelSource(): void
    {
        $entityManager = $this->entityManager();
        [$tenant] = $this->tenantOwner($entityManager);
        $legalA = new LegalEntity($tenant, 'Entidad A', null, true);
        $legalB = new LegalEntity($tenant, 'Entidad B', null, false);
        $sourceA = new InventorySource(
            $tenant,
            $legalA,
            'Bodega A',
            'bodega-a-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $list = new PriceList(
            $tenant,
            'Lista DB',
            'lista-db-'.bin2hex(random_bytes(3)),
        );
        foreach ([$legalA, $legalB, $sourceA, $list] as $record) {
            $entityManager->persist($record);
        }
        $entityManager->flush();

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $entityManager->getConnection()->executeStatement(
            'INSERT INTO condor_sales_channel '
            .'(id, tenant_id, legal_entity_id, inventory_source_id, '
            .'price_list_id, name, slug, type, active, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                UlidFactory::new(),
                $tenant->id(),
                $legalB->id(),
                $sourceA->id(),
                $list->id(),
                'Canal inválido',
                'canal-invalido-'.bin2hex(random_bytes(3)),
                SalesChannel::TYPE_ECOMMERCE,
            ],
        );
    }

    public function testTenantKeepsSingleChannelPerType(): void
    {
        $entityManager = $this->entityManager();
        [$tenant] = $this->tenantOwner($entityManager);
        $legal = new LegalEntity($tenant, 'Entidad canal', null, true);
        $source = new InventorySource(
            $tenant,
            $legal,
            'Bodega canal',
            'bodega-canal-'.bin2hex(random_bytes(3)),
            InventorySource::TYPE_LOGICAL,
        );
        $list = new PriceList(
            $tenant,
            'Lista canal',
            'lista-canal-'.bin2hex(random_bytes(3)),
        );
        $first = new SalesChannel(
            $tenant,
            'Web principal',
            'web-principal',
            $source,
            $list,
        );
        $second = new SalesChannel(
            $tenant,
            'Web alterna',
            'web-alterna',
            $source,
            $list,
        );

        foreach ([$legal, $source, $list, $first, $second] as $record) {
            $entityManager->persist($record);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    /**
     * @return array{0: KernelBrowser, 1: EntityManagerInterface, 2: Tenant, 3: User}
     */
    private function tenantBrowser(): array
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        [$tenant, $owner] = $this->tenantOwner($entityManager);

        return [$client, $entityManager, $tenant, $owner];
    }

    private function registerVerifiedPrimaryDomain(
        EntityManagerInterface $entityManager,
        Tenant $tenant,
        string $host,
    ): void {
        $entityManager->persist(new TenantDomain(
            $tenant,
            $host,
            true,
            true,
        ));
        $entityManager->flush();
    }

    private function storedProfile(Tenant $tenant): StorefrontProfile
    {
        $profile = $this->entityManager()
            ->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(StorefrontProfile::class, $profile);

        return $profile;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantOwner(EntityManagerInterface $entityManager): array
    {
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
        $owner = new User(
            'owner-'.$suffix.'@example.test',
            'Propietario',
        );
        $membership = new Membership(
            $tenant,
            $owner,
            Membership::ROLE_OWNER,
        );

        foreach ([$tenant, $branch, $owner, $membership] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [$tenant, $owner];
    }
}
