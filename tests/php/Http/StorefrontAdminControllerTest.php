<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\BrowserCsrfToken;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
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

        self::assertResponseRedirects('/admin/storefront?saved=1');

        $profile = $entityManager->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertInstanceOf(StorefrontProfile::class, $profile);
        self::assertSame('Ferretería del barrio', $profile->headline());

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

    public function testDelegatedUserNeedsSitePermissionAndViewOnlyStaysReadOnly(): void
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
            ['site.view', 'site.update'],
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
