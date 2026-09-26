<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ChangeOwnPassword;
use App\Application\Identity\CompletePasswordReset;
use App\Application\Identity\RequestPasswordReset;
use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordRecoveryFlowTest extends KernelTestCase
{
    public function testResetTokenIsHashedSingleUseAndChangesPassword(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $requestReset = $container->get(RequestPasswordReset::class);
        $completeReset = $container->get(CompletePasswordReset::class);
        $queue = $container->get(DeferredTransactionalEmailQueue::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(RequestPasswordReset::class, $requestReset);
        self::assertInstanceOf(CompletePasswordReset::class, $completeReset);
        self::assertInstanceOf(DeferredTransactionalEmailQueue::class, $queue);

        $user = $this->persistUser(
            $entityManager,
            $hasher,
            'reset-'.bin2hex(random_bytes(4)).'@example.test',
            'Old-secure-password-123!',
        );

        try {
            $requestReset->request($user->email());

            $messages = $queue->drain();
            self::assertCount(1, $messages);
            self::assertSame('account_password_reset', $messages[0]->templateKey);

            $rawToken = self::tokenFromResetUrl(
                (string) $messages[0]->templateData['reset_url'],
            );
            $reset = $entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);

            self::assertInstanceOf(PasswordResetToken::class, $reset);
            self::assertSame(hash('sha256', $rawToken), $reset->tokenHash());
            self::assertNotSame($rawToken, $reset->tokenHash());

            $completeReset->complete($rawToken, 'New-secure-password-456!');

            self::assertTrue(
                $hasher->isPasswordValid($user, 'New-secure-password-456!'),
            );
            self::assertFalse(
                $hasher->isPasswordValid($user, 'Old-secure-password-123!'),
            );
            self::assertNotNull($reset->consumedAt());

            $this->expectException(DomainException::class);
            $completeReset->complete($rawToken, 'Another-secure-password-789!');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testNewRequestInvalidatesPreviousTokenAndUnknownEmailQueuesNothing(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $requestReset = $container->get(RequestPasswordReset::class);
        $completeReset = $container->get(CompletePasswordReset::class);
        $queue = $container->get(DeferredTransactionalEmailQueue::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(RequestPasswordReset::class, $requestReset);
        self::assertInstanceOf(CompletePasswordReset::class, $completeReset);
        self::assertInstanceOf(DeferredTransactionalEmailQueue::class, $queue);

        $user = $this->persistUser(
            $entityManager,
            $hasher,
            'reissue-'.bin2hex(random_bytes(4)).'@example.test',
            'Current-secure-password-123!',
        );

        try {
            $requestReset->request($user->email());
            $first = self::tokenFromResetUrl(
                (string) $queue->drain()[0]->templateData['reset_url'],
            );

            $requestReset->request($user->email());
            $second = self::tokenFromResetUrl(
                (string) $queue->drain()[0]->templateData['reset_url'],
            );

            self::assertNotSame($first, $second);

            try {
                $completeReset->complete($first, 'Replacement-password-456!');
                self::fail('El token anterior debe quedar invalidado al reenviar.');
            } catch (DomainException) {
                self::assertTrue(true);
            }

            $requestReset->request(
                'missing-'.bin2hex(random_bytes(4)).'@example.test',
            );
            self::assertSame([], $queue->drain());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testAuthenticatedChangeRevokesPendingResetAndQueuesConfirmation(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $requestReset = $container->get(RequestPasswordReset::class);
        $changePassword = $container->get(ChangeOwnPassword::class);
        $queue = $container->get(DeferredTransactionalEmailQueue::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(RequestPasswordReset::class, $requestReset);
        self::assertInstanceOf(ChangeOwnPassword::class, $changePassword);
        self::assertInstanceOf(DeferredTransactionalEmailQueue::class, $queue);

        $user = $this->persistUser(
            $entityManager,
            $hasher,
            'change-'.bin2hex(random_bytes(4)).'@example.test',
            'Current-secure-password-123!',
        );

        try {
            $requestReset->request($user->email());
            $queue->drain();

            $reset = $entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);
            self::assertInstanceOf(PasswordResetToken::class, $reset);

            $changePassword->change(
                $user,
                'Current-secure-password-123!',
                'Changed-secure-password-456!',
            );

            self::assertTrue(
                $hasher->isPasswordValid(
                    $user,
                    'Changed-secure-password-456!',
                ),
            );
            self::assertNotNull($reset->revokedAt());

            $messages = $queue->drain();
            self::assertCount(1, $messages);
            self::assertSame(
                'account_password_changed',
                $messages[0]->templateKey,
            );
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testInvalidCurrentPasswordDoesNotRotateHashOrRevokePendingReset(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $requestReset = $container->get(RequestPasswordReset::class);
        $changePassword = $container->get(ChangeOwnPassword::class);
        $queue = $container->get(DeferredTransactionalEmailQueue::class);

        $user = $this->persistUser(
            $entityManager,
            $hasher,
            'wrong-current-'.bin2hex(random_bytes(4)).'@example.test',
            'Current-secure-password-123!',
        );

        try {
            $requestReset->request($user->email());
            $queue->drain();
            $reset = $entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);
            self::assertInstanceOf(PasswordResetToken::class, $reset);
            $originalHash = $user->getPassword();

            try {
                $changePassword->change(
                    $user,
                    'Wrong-current-password-123!',
                    'Replacement-secure-password-456!',
                );
                self::fail('La contraseña actual incorrecta debe ser rechazada.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'La contraseña actual no es válida.',
                    $exception->getMessage(),
                );
            }

            // Doctrine cierra el EntityManager si wrapInTransaction() lanza.
            // El hash y el token no deben haberse mutado antes del rollback.
            self::assertSame($originalHash, $user->getPassword());
            self::assertTrue(
                $hasher->isPasswordValid($user, 'Current-secure-password-123!'),
            );
            self::assertNull($reset->revokedAt());
            self::assertNull($reset->consumedAt());
            self::assertSame([], $queue->drain());
        } finally {
            if (!$entityManager->isOpen()) {
                // Doctrine cierra el manager al revertir la transacción.
                static::ensureKernelShutdown();
                static::bootKernel();
                $entityManager = static::getContainer()->get(EntityManagerInterface::class);
                $reloadedUser = $entityManager->find(User::class, $user->id());
                self::assertInstanceOf(User::class, $reloadedUser);
                $user = $reloadedUser;
            }
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function persistUser(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        string $email,
        string $password,
    ): User {
        $user = new User($email, 'Admin de prueba');
        $user->setPasswordHash($hasher->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function cleanupUser(
        EntityManagerInterface $entityManager,
        User $user,
    ): void {
        $entityManager->getConnection()->executeStatement(
            'DELETE FROM condor_platform_audit_event WHERE actor_user_id = :id',
            ['id' => $user->id()],
        );

        if ($entityManager->contains($user)) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }

    private static function tokenFromResetUrl(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        self::assertIsString($fragment);

        parse_str($fragment, $values);
        $token = $values['token'] ?? null;
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);

        return $token;
    }
}
