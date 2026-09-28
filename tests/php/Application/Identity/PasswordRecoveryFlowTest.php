<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\ChangeOwnPassword;
use App\Application\Identity\CompletePasswordReset;
use App\Application\Identity\RequestPasswordReset;
use App\Application\Notification\DeferredTransactionalEmailQueue;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordRecoveryFlowTest extends KernelTestCase
{
    public function testRequestReissuesHashedTokenAndCompleteRotatesPassword(): void
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

        $connection = $entityManager->getConnection();
        $user = $this->persistUser(
            $entityManager,
            $hasher,
            'reset-'.bin2hex(random_bytes(4)).'@example.test',
            'Old-secure-password-123!',
        );

        try {
            $requestReset->request('missing-'.bin2hex(random_bytes(4)).'@example.test');
            self::assertSame([], $queue->drain());

            $requestReset->request($user->email());
            $firstMessage = $queue->drain()[0] ?? null;
            self::assertNotNull($firstMessage);
            $firstToken = self::tokenFromResetUrl((string) $firstMessage->templateData['reset_url']);

            $reset = $entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $user]);
            self::assertInstanceOf(PasswordResetToken::class, $reset);
            self::assertSame(hash('sha256', $firstToken), $reset->tokenHash());
            self::assertNotSame($firstToken, $reset->tokenHash());

            $requestReset->request($user->email());
            $secondMessage = $queue->drain()[0] ?? null;
            self::assertNotNull($secondMessage);
            $secondToken = self::tokenFromResetUrl((string) $secondMessage->templateData['reset_url']);
            self::assertNotSame($firstToken, $secondToken);
            self::assertSame(hash('sha256', $secondToken), $reset->tokenHash());
            self::assertNull(
                $entityManager->getRepository(PasswordResetToken::class)
                    ->findOneBy(['tokenHash' => hash('sha256', $firstToken)]),
            );
            try {
                $completeReset->complete($firstToken, 'Ignored-secure-password-789!');
                self::fail('El token anterior debe quedar invalidado al reenviar.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
            self::assertSame([], $queue->drain());

            $completeReset->complete($secondToken, 'New-secure-password-456!');
            self::assertTrue($hasher->isPasswordValid($user, 'New-secure-password-456!'));
            self::assertFalse($hasher->isPasswordValid($user, 'Old-secure-password-123!'));
            self::assertNotNull($reset->consumedAt());
            self::assertFalse($reset->isUsableAt($reset->expiresAt()->modify('-1 second')));

            $messages = $queue->drain();
            self::assertCount(1, $messages);
            self::assertSame('account_password_changed', $messages[0]->templateKey);
            self::assertSame('recovery', $messages[0]->templateData['source']);

            try {
                $completeReset->complete($secondToken, 'Another-secure-password-789!');
                self::fail('Un token consumido no puede reutilizarse.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        } finally {
            $this->cleanupUser($connection, $user->id());
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

        $connection = $entityManager->getConnection();
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

            self::assertTrue($hasher->isPasswordValid($user, 'Changed-secure-password-456!'));
            self::assertNotNull($reset->revokedAt());
            $messages = $queue->drain();
            self::assertCount(1, $messages);
            self::assertSame('account_password_changed', $messages[0]->templateKey);
            self::assertSame('authenticated_change', $messages[0]->templateData['source']);
        } finally {
            $this->cleanupUser($connection, $user->id());
        }
    }

    public function testInvalidCurrentPasswordDoesNotMutateCredentialOrPendingReset(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $requestReset = $container->get(RequestPasswordReset::class);
        $changePassword = $container->get(ChangeOwnPassword::class);
        $queue = $container->get(DeferredTransactionalEmailQueue::class);

        $connection = $entityManager->getConnection();
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
                self::assertSame('La contraseña actual no es válida.', $exception->getMessage());
            }

            self::assertSame($originalHash, $user->getPassword());
            self::assertTrue($hasher->isPasswordValid($user, 'Current-secure-password-123!'));
            self::assertNull($reset->revokedAt());
            self::assertNull($reset->consumedAt());
            self::assertSame([], $queue->drain());
        } finally {
            $this->cleanupUser($connection, $user->id());
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

    private function cleanupUser(Connection $connection, string $userId): void
    {
        $connection->executeStatement(
            'DELETE FROM condor_platform_audit_event WHERE actor_user_id = :id',
            ['id' => $userId],
        );
        $connection->executeStatement(
            'DELETE FROM condor_password_reset WHERE user_id = :id',
            ['id' => $userId],
        );
        $connection->executeStatement(
            'DELETE FROM condor_user WHERE id = :id',
            ['id' => $userId],
        );
    }

    private static function tokenFromResetUrl(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        self::assertIsString($fragment);
        self::assertStringStartsWith('token=', $fragment);

        $token = substr($fragment, strlen('token='));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);

        return $token;
    }
}
