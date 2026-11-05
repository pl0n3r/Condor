<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Application\Identity\BranchAuthorization;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Condor #1539 / M14: evidencia reproducible sin cuentas ni secretos reales.
 * No confunde controles de autenticación existentes con una auditoría
 * integral de recertificación, backups ni recuperación en producción.
 */
final class GestM14SecurityContractTest extends WebTestCase
{
    public function testAnonymousLoginAndPasswordRecoveryDoNotCachePersonalData(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/login',
            '/admin/recuperar-contrasena',
            '/admin/restablecer-contrasena',
        ] as $path) {
            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(200);
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            self::assertTrue(
                $client->getResponse()->headers->hasCacheControlDirective('no-store'),
                $path.' debe deshabilitar la caché',
            );
            self::assertTrue(
                $client->getResponse()->headers->hasCacheControlDirective('private'),
                $path.' no debe permitir caché compartida',
            );
        }
    }

    public function testAnonymousPasswordMutationRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/seguridad/contrasena', [
            'current_password' => 'synthetic-old',
            'new_password' => 'synthetic-new',
            'password_confirmation' => 'synthetic-new',
        ]);

        self::assertResponseRedirects('/admin/login');
    }

    public function testRolePermissionsRejectForeignTenantBranchBeforeRepositoryLookup(): void
    {
        $alpha = new Tenant('M14 A sintética', 'm14-alpha');
        $beta = new Tenant('M14 B sintética', 'm14-beta');
        $foreignBranch = new Branch($beta, 'Sede Beta', 'sede-beta');
        $user = new User('m14-owner@example.invalid', 'Dueño sintético');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $authorization = new BranchAuthorization($em);

        self::assertFalse(
            $authorization->canAccessBranch($user, $alpha, $foreignBranch),
        );

        $this->expectException(AccessDeniedException::class);
        $authorization->permissions($user, $alpha, $foreignBranch);
    }

    public function testDelegatedMemberWithoutRoleCannotGrantUserPermissions(): void
    {
        $tenant = new Tenant('M14 autorizaciones', 'm14-roles');
        $branch = new Branch($tenant, 'Sede A', 'sede-a');
        $user = new User('m14-staff@example.invalid', 'Staff sintético');
        $membership = new Membership($tenant, $user, 'ADMIN');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($membership);
        $repository->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $authorization = new BranchAuthorization($em);

        self::assertSame([], $authorization->permissions($user, $tenant, $branch));
        self::assertFalse(
            $authorization->hasAnyPermission($user, $tenant, ['users.update']),
        );

        $this->expectException(AccessDeniedException::class);
        $authorization->require($user, $tenant, $branch, 'users.update');
    }

    public function testNoMembershipDeniesAccessEvenWhenBranchIsFromRequestedTenant(): void
    {
        $tenant = new Tenant('M14 sin afiliación', 'm14-no-membership');
        $branch = new Branch($tenant, 'Sede A', 'sede-a');
        $user = new User('m14-visitor@example.invalid', 'Visitante sintético');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->expectException(AccessDeniedException::class);
        (new BranchAuthorization($em))->permissions($user, $tenant, $branch);
    }
}
