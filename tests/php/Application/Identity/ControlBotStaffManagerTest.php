<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ControlBotStaffManager;
use App\Application\Identity\PlatformInvitationSecurity;
use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ControlBotStaffManagerTest extends KernelTestCase
{
    public function testInvitationDeliveryRevocationAndReactivationAreExplicit(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $sent = [];
        $gateway = $this->createMock(TransactionalEmailGateway::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->expects(self::once())->method('deliver')
            ->willReturnCallback(static function (TransactionalEmailMessage $message) use (&$sent): void {
                $sent[] = $message;
            });
        $manager = new ControlBotStaffManager(
            $entityManager,
            new PlatformInvitationSecurity($entityManager),
            $gateway,
        );
        $suffix = bin2hex(random_bytes(6));
        $result = $manager->invite(
            '  STAFF-'.$suffix.'@EXAMPLE.TEST  ',
            '  Personal sintético  ',
            'staff',
        );
        $staff = $result->user;
        $invitation = $result->invitation;
        try {
            self::assertSame('staff-'.$suffix.'@example.test', $staff->email());
            self::assertSame('Personal sintético', $staff->displayName());
            self::assertFalse($staff->isActive());
            self::assertTrue($staff->hasRole(User::ROLE_PLATFORM_STAFF));
            self::assertFalse($staff->hasRole(User::ROLE_PLATFORM_OWNER));
            self::assertSame(AccountInvitation::KIND_PLATFORM_STAFF, $invitation->kind());
            self::assertNull($invitation->tenant());
            self::assertSame(hash('sha256', $result->rawToken), $invitation->tokenHash());
            self::assertSame([], $sent, 'Invitar no debe enviar correo por sí solo.');

            $entityManager->flush();
            $manager->deliverInvitation($result);
            self::assertCount(1, $sent);
            self::assertSame($staff->email(), $sent[0]->recipient);
            self::assertSame('platform_staff_invitation', $sent[0]->templateKey);
            self::assertSame($result->rawToken, $sent[0]->templateData['activation_token']);
            self::assertSame($staff->displayName(), $sent[0]->templateData['display_name']);

            self::assertRejects(static fn () => $manager->reactivate($staff));
            $oldHash = $invitation->tokenHash();
            $replacement = $manager->reissueInvitation($staff);
            self::assertSame($invitation->id(), $replacement->invitation->id());
            self::assertNotSame($oldHash, $invitation->tokenHash());
            self::assertSame(hash('sha256', $replacement->rawToken), $invitation->tokenHash());

            $manager->revokeInvitation($replacement);
            self::assertNotNull($invitation->revokedAt());
            self::assertFalse($invitation->isUsableAt(new \DateTimeImmutable('now')));
            $staff->setPasswordHash('synthetic-non-empty-hash');
            $manager->reactivate($staff);
            self::assertTrue($staff->isActive());
            $manager->suspend($staff);
            self::assertFalse($staff->isActive());
            $manager->setRole($staff, ' staff ');
            self::assertTrue($staff->hasRole(User::ROLE_PLATFORM_STAFF));
            self::assertRejects(static fn () => $manager->setRole($staff, 'owner'));
        } finally {
            if ($entityManager->isOpen()) {
                $entityManager->remove($invitation);
                $entityManager->remove($staff);
                $entityManager->flush();
            }
        }
    }

    public function testInvalidOrUnavailableInvitationsCannotPersistAccounts(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('getRepository');
        $gateway = $this->createMock(TransactionalEmailGateway::class);
        $gateway->method('isAvailable')->willReturn(true);
        $manager = new ControlBotStaffManager(
            $entityManager, new PlatformInvitationSecurity($entityManager), $gateway,
        );
        foreach ([
            ['invalid-email', 'Personal', 'staff'],
            ['synthetic@example.test', '', 'staff'],
            ['synthetic@example.test', str_repeat('x', 161), 'staff'],
            ['synthetic@example.test', 'Personal', 'owner'],
            ['synthetic@example.test', 'Personal', 'admin'],
        ] as [$email, $name, $role]) {
            self::assertRejects(static fn () => $manager->invite($email, $name, $role));
        }

        $unavailable = $this->createMock(TransactionalEmailGateway::class);
        $unavailable->method('isAvailable')->willReturn(false);
        $disabledManager = new ControlBotStaffManager(
            $entityManager, new PlatformInvitationSecurity($entityManager), $unavailable,
        );
        self::assertRejects(static fn () => $disabledManager->invite(
            'synthetic@example.test', 'Personal', 'staff',
        ));
    }

    public function testNonStaffAndPrivilegedAccountsCannotBeMutatedByControlBot(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $gateway = $this->createMock(TransactionalEmailGateway::class);
        $manager = new ControlBotStaffManager(
            $entityManager, new PlatformInvitationSecurity($entityManager), $gateway,
        );
        foreach ([
            new User('owner@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]),
            new User('legacy@example.test', 'Legacy', [User::ROLE_LEGACY_SUPER_ADMIN]),
            new User('tenant@example.test', 'Tenant'),
            new User('dual@example.test', 'Dual', [User::ROLE_PLATFORM_STAFF, User::ROLE_PLATFORM_OWNER]),
        ] as $account) {
            self::assertRejects(static fn () => $manager->suspend($account));
            self::assertRejects(static fn () => $manager->reactivate($account));
            self::assertRejects(static fn () => $manager->setRole($account, 'staff'));
            self::assertRejects(static fn () => $manager->reissueInvitation($account));
        }
    }

    private static function assertRejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Se esperaba rechazo fail-closed para la operación.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
