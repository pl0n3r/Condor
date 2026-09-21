<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PlatformTenantControllerTest extends WebTestCase
{
    public function testOwnerCreatesTenantAndInvitesTenantOwnerWithoutPassword(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $platformOwner = new User(
            'platform-owner-tenant-'.$suffix.'@example.test',
            'Propietario Condor',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($platformOwner);
        $entityManager->flush();

        $client->loginUser($platformOwner);
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        $csrf = $this->csrfToken(
            $client,
            'platform_tenant_management',
        );
        $slug = 'cliente-superadmin-'.$suffix;
        $ownerEmail = 'cliente-owner-'.$suffix.'@example.test';

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants',
            [
                'name' => 'Cliente Super Admin '.$suffix,
                'slug' => $slug,
                'legal_name' => 'Cliente Super Admin SAS',
                'nit' => '900123456-1',
                'branch_name' => 'Principal',
                'owner_email' => $ownerEmail,
                'owner_name' => 'Administradora Cliente',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($slug, $payload['tenant']['slug']);
        self::assertSame('Principal', $payload['branch']['name']);
        self::assertFalse($payload['owner']['active']);
        self::assertSame('manual', $payload['invitation']['delivery']);

        $activationPath = $payload['invitation']['activation_path_once'];
        self::assertIsString($activationPath);
        self::assertMatchesRegularExpression(
            '#^/activar-cuenta/[a-f0-9]{64}$#',
            $activationPath,
        );
        $rawToken = basename($activationPath);

        $tenant = $entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $slug]);
        $owner = $entityManager->getRepository(User::class)
            ->findOneBy(['email' => $ownerEmail]);

        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertInstanceOf(User::class, $owner);
        self::assertFalse($owner->isActive());
        self::assertSame('', $owner->getPassword());

        $membership = $entityManager->getRepository(Membership::class)
            ->findOneBy(['tenant' => $tenant, 'user' => $owner]);
        self::assertInstanceOf(Membership::class, $membership);
        self::assertSame(Membership::ROLE_OWNER, $membership->roleKey());

        $branch = $entityManager->getRepository(Branch::class)
            ->findOneBy(['tenant' => $tenant, 'default' => true]);
        self::assertInstanceOf(Branch::class, $branch);
        self::assertSame('Principal', $branch->name());

        $invitation = $entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy(['user' => $owner]);
        self::assertInstanceOf(AccountInvitation::class, $invitation);
        self::assertSame(
            AccountInvitation::KIND_TENANT_MEMBER,
            $invitation->kind(),
        );
        self::assertSame($tenant->id(), $invitation->tenant()?->id());
        self::assertSame(
            hash('sha256', $rawToken),
            $invitation->tokenHash(),
        );
    }

    public function testNormalUserCannotCreateTenant(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'normal-tenant-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario normal',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants',
            [],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidTenantCreationRollsBackOwnerAndTenant(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $platformOwner = new User(
            'platform-owner-invalid-'.$suffix.'@example.test',
            'Propietario Condor',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($platformOwner);
        $entityManager->flush();

        $client->loginUser($platformOwner);
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        $csrf = $this->csrfToken(
            $client,
            'platform_tenant_management',
        );
        $ownerEmail = 'invalid-owner-'.$suffix.'@example.test';

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/tenants',
            [
                'name' => 'Cliente inválido',
                'slug' => 'Slug Con Espacios',
                'legal_name' => 'Cliente inválido SAS',
                'branch_name' => 'Principal',
                'owner_email' => $ownerEmail,
                'owner_name' => 'Administradora',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $entityManager->getRepository(User::class)
                ->findOneBy(['email' => $ownerEmail]),
        );
        self::assertNull(
            $entityManager->getRepository(Tenant::class)
                ->findOneBy(['name' => 'Cliente inválido']),
        );
    }

    private function csrfToken(
        KernelBrowser $client,
        string $tokenId,
    ): string {
        $request = $client->getRequest();
        self::assertNotNull($request);

        $container = static::getContainer();
        $requestStack = $container->get(RequestStack::class);
        $csrf = $container->get(CsrfTokenManagerInterface::class);
        $sessionFactory = $container->get('session.factory');

        self::assertInstanceOf(RequestStack::class, $requestStack);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);
        self::assertTrue(method_exists($sessionFactory, 'createSession'));

        $session = $sessionFactory->createSession();
        $existingCookie = $client->getCookieJar()->get(
            $session->getName(),
        );
        if ($existingCookie !== null) {
            $session->setId((string) $existingCookie->getValue());
        }

        $session->start();
        $request->setSession($session);

        $requestStack->push($request);
        try {
            $value = $csrf->getToken($tokenId)->getValue();
            $session->save();
        } finally {
            $requestStack->pop();
        }

        $client->getCookieJar()->set(
            new Cookie($session->getName(), $session->getId()),
        );

        return $value;
    }
}
