<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PlatformStaffControllerTest extends WebTestCase
{
    public function testOwnerInvitesStaffWithHashedSingleUseTokenAndActivatesAccount(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $owner = new User(
            'platform-owner-'.$suffix.'@example.test',
            'Propietario Condor',
            [User::ROLE_PLATFORM_OWNER],
        );
        $tenant = new Tenant(
            'Cliente '.$suffix,
            'cliente-'.$suffix,
        );
        $entityManager->persist($owner);
        $entityManager->persist($tenant);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        $managementToken = $this->csrfToken(
            $client,
            'platform_staff_management',
        );

        $email = 'staff-'.$suffix.'@example.test';
        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/staff/invitations',
            [
                'email' => $email,
                'display_name' => 'Operador Condor',
                'grants' => [[
                    'tenant_id' => $tenant->id(),
                    'module' => 'catalog',
                    'actions' => ['view', 'update'],
                ]],
            ],
            ['HTTP_X_CSRF_TOKEN' => $managementToken],
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('manual', $payload['invitation']['delivery']);

        $activationPath = $payload['invitation']['activation_path_once'];
        self::assertIsString($activationPath);
        self::assertMatchesRegularExpression(
            '#^/activar-cuenta/[a-f0-9]{64}$#',
            $activationPath,
        );
        $rawToken = basename($activationPath);

        $staff = $entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $staff);
        self::assertFalse($staff->isActive());
        self::assertTrue($staff->hasRole(User::ROLE_PLATFORM_STAFF));
        self::assertFalse($staff->hasRole(User::ROLE_PLATFORM_OWNER));
        self::assertSame(
            0,
            $entityManager->getRepository(Membership::class)
                ->count(['user' => $staff]),
        );

        $invitation = $entityManager
            ->getRepository(AccountInvitation::class)
            ->findOneBy(['user' => $staff]);
        self::assertInstanceOf(AccountInvitation::class, $invitation);
        self::assertNotSame($rawToken, $invitation->tokenHash());
        self::assertSame(
            hash('sha256', $rawToken),
            $invitation->tokenHash(),
        );

        $client->request('GET', $activationPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'h1',
            'Activa tu cuenta de Condor',
        );

        $activationCsrf = $this->csrfToken(
            $client,
            'invitation_accept_'.$invitation->id(),
        );
        $plainPassword = 'ClaveNuevaSegura-2026!';
        $client->request(
            'POST',
            $activationPath,
            [
                '_csrf_token' => $activationCsrf,
                'password' => $plainPassword,
                'password_confirmation' => $plainPassword,
            ],
        );

        self::assertResponseRedirects('/admin/login');
        self::assertTrue($staff->isActive());
        self::assertNotNull($invitation->consumedAt());

        $hasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue(
            $hasher->isPasswordValid($staff, $plainPassword),
        );

        $client->request('GET', $activationPath);
        self::assertResponseStatusCodeSame(410);

        $client->request('GET', '/adminpl0n3r/api/staff');
        self::assertResponseIsSuccessful();
        $overview = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($staff->id(), $overview['staff'][0]['id']);
        self::assertTrue($overview['staff'][0]['active']);
        self::assertSame(
            'active',
            $overview['staff'][0]['invitation']['state'],
        );
    }

    public function testNormalUserCannotManagePlatformStaff(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'normal-staff-test-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario normal',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r/api/staff');

        self::assertResponseStatusCodeSame(403);
    }

    public function testInviteRequiresCsrfAndRejectsUnknownTenantAtomically(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(4));
        $owner = new User(
            'owner-negative-'.$suffix.'@example.test',
            'Propietario',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();
        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r');
        self::assertResponseIsSuccessful();

        $managementToken = $this->csrfToken(
            $client,
            'platform_staff_management',
        );

        $email = 'rejected-'.$suffix.'@example.test';
        $body = [
            'email' => $email,
            'display_name' => 'Rechazado',
            'grants' => [[
                'tenant_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
                'module' => 'orders',
                'actions' => ['view'],
            ]],
        ];

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/staff/invitations',
            $body,
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'POST',
            '/adminpl0n3r/api/staff/invitations',
            $body,
            ['HTTP_X_CSRF_TOKEN' => $managementToken],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]),
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

        self::assertInstanceOf(RequestStack::class, $requestStack);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);

        $requestStack->push($request);
        try {
            return $csrf->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
    }
}
