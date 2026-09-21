<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontAdminControllerTest extends WebTestCase
{
    public function testOwnerCanEditIdentityAndSeeItOnPublicSlug(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant, $owner] = $this->tenantOwner($entityManager);

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

    public function testVerifiedPrimaryDomainServesSameTenantAndCanonical(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant] = $this->tenantOwner($entityManager);
        $host = 'tienda-'.strtolower(bin2hex(random_bytes(4))).'.example.test';

        $entityManager->persist(new StorefrontProfile(
            $tenant,
            'Catálogo de prueba',
            'El mismo storefront servido desde un dominio verificado.',
        ));
        $entityManager->persist(new TenantDomain(
            $tenant,
            $host,
            true,
            true,
        ));
        $entityManager->flush();

        $client->request('GET', '/', server: ['HTTP_HOST' => $host]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catálogo de prueba');
        self::assertSelectorExists(
            'link[rel="canonical"][href="https://'.$host.'/"]',
        );
    }

    public function testUnverifiedCustomHostFailsClosed(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        [$tenant] = $this->tenantOwner($entityManager);
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
