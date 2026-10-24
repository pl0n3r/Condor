<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\PlatformInvitationSecurity;
use App\Application\Identity\PlatformStaffAuthorization;
use App\Application\Identity\PlatformStaffManager;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\PlatformStaffGrant;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PlatformStaffManagerTest extends KernelTestCase
{
    public function testInvitationGrantReplacementAndRevocationAreTenantScoped(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $manager = new PlatformStaffManager(
            $entityManager,
            new PlatformInvitationSecurity($entityManager),
        );
        $authorization = new PlatformStaffAuthorization($entityManager);
        $suffix = bin2hex(random_bytes(5));
        $owner = new User('owner-'.$suffix.'@example.test', 'Dueño', [User::ROLE_PLATFORM_OWNER]);
        $tenantA = new Tenant('Primera '.$suffix, 'staff-a-'.$suffix);
        $tenantB = new Tenant('Segunda '.$suffix, 'staff-b-'.$suffix);
        foreach ([$owner, $tenantA, $tenantB] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $staff = null;

        try {
            $result = $manager->invite(
                $owner,
                '  EMPLEADO-'.$suffix.'@EXAMPLE.TEST  ',
                '  Personal de prueba  ',
                [[
                    'tenant_id' => $tenantA->id(),
                    'module' => 'catalog',
                    'actions' => ['view'],
                ]],
            );
            $staff = $result->user;
            self::assertFalse($staff->isActive());
            self::assertSame('empleado-'.$suffix.'@example.test', $staff->email());
            self::assertSame('Personal de prueba', $staff->displayName());
            self::assertSame(AccountInvitation::KIND_PLATFORM_STAFF, $result->invitation->kind());
            self::assertSame(hash('sha256', $result->rawToken), $result->invitation->tokenHash());
            self::assertSame([
                ['tenant_id' => $tenantA->id(), 'module' => 'catalog', 'actions' => ['view']],
            ], $manager->grantPayloads($staff));

            $staff->activate();
            $entityManager->flush();
            self::assertTrue($authorization->can($staff, $tenantA, 'catalog.view'));
            self::assertFalse($authorization->can($staff, $tenantB, 'catalog.view'));
            self::assertFalse($authorization->can($staff, $tenantA, 'catalog.delete'));

            $newGrants = $manager->replaceGrants($owner, $staff, [[
                'tenant_id' => $tenantB->id(),
                'module' => 'orders',
                'actions' => ['view'],
            ]]);
            self::assertCount(1, $newGrants);
            self::assertSame([
                ['tenant_id' => $tenantB->id(), 'module' => 'orders', 'actions' => ['view']],
            ], $manager->grantPayloads($staff));
            self::assertFalse($authorization->can($staff, $tenantA, 'catalog.view'));
            self::assertTrue($authorization->can($staff, $tenantB, 'orders.view'));
            self::assertFalse($authorization->can($staff, $tenantA, 'orders.view'));

            $staff->deactivate();
            $entityManager->flush();
            $oldHash = $result->invitation->tokenHash();
            $reissued = $manager->reissueInvitation($owner, $staff);
            self::assertSame($result->invitation->id(), $reissued->invitation->id());
            self::assertNotSame($oldHash, $reissued->invitation->tokenHash());
            self::assertSame(hash('sha256', $reissued->rawToken), $reissued->invitation->tokenHash());
            $manager->revokeInvitation($owner, $staff);
            self::assertNotNull($reissued->invitation->revokedAt());
            self::assertFalse($reissued->invitation->isUsableAt(new \DateTimeImmutable('now')));
            // Repeated revocation must remain revoked, never reactivate a token.
            $manager->revokeInvitation($owner, $staff);
            self::assertNotNull($reissued->invitation->revokedAt());
        } finally {
            if ($entityManager->isOpen()) {
                if ($staff instanceof User) {
                    foreach ($entityManager->getRepository(PlatformStaffGrant::class)->findBy(['staff' => $staff]) as $grant) {
                        $entityManager->remove($grant);
                    }
                    $invitation = $entityManager->getRepository(AccountInvitation::class)->findOneBy(['user' => $staff]);
                    if ($invitation instanceof AccountInvitation) {
                        $entityManager->remove($invitation);
                    }
                    $entityManager->remove($staff);
                }
                $entityManager->remove($owner);
                $entityManager->remove($tenantA);
                $entityManager->remove($tenantB);
                $entityManager->flush();
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM condor_platform_audit_event WHERE actor_user_id = :owner',
                    ['owner' => $owner->id()],
                );
            }
        }
    }

    public function testTenantAdminCannotInvitePlatformStaff(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $manager = new PlatformStaffManager(
            $entityManager,
            new PlatformInvitationSecurity($entityManager),
        );
        // An unpersisted tenant admin cannot satisfy the server-side fresh owner lock.
        $tenantAdmin = new User('tenant-admin@example.test', 'Tenant admin');
        $this->expectException(AccessDeniedException::class);
        $manager->invite($tenantAdmin, 'new-staff@example.test', 'Unauthorized', [[
            'tenant_id' => null,
            'module' => 'catalog',
            'actions' => ['view'],
        ]]);
    }
}
