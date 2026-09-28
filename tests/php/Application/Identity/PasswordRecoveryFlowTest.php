<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\AccountPasswordNotifier;
use App\Application\Identity\AccountPasswordPolicy;
use App\Application\Identity\ChangeOwnPassword;
use App\Application\Identity\CompletePasswordReset;
use App\Application\Identity\PasswordCredentialLock;
use App\Application\Identity\PasswordResetSecurity;
use App\Application\Identity\PasswordResetUrlFactory;
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
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;
    private DeferredTransactionalEmailQueue $queue;
    private RequestPasswordReset $requestReset;
    private CompletePasswordReset $completeReset;
    private ChangeOwnPassword $changePassword;
    private Connection $connection;

    /** @var list<string> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $this->entityManager = $entityManager;
        $this->hasher = $hasher;
        $this->connection = $entityManager->getConnection();
        $this->queue = new DeferredTransactionalEmailQueue();

        $security = new PasswordResetSecurity();
        $notifier = new AccountPasswordNotifier($this->queue);
        $policy = new AccountPasswordPolicy($hasher);
        $lock = new PasswordCredentialLock($entityManager);

        $this->requestReset = new RequestPasswordReset(
            $entityManager,
            $security,
            new PasswordResetUrlFactory('https://secure.example.test'),
            $notifier,
            $lock,
        );
        $this->completeReset = new CompletePasswordReset(
            $entityManager,
            $security,
            $policy,
            $hasher,
            $notifier,
            $lock,
        );
        $this->changePassword = new ChangeOwnPassword(
            $entityManager,
            $policy,
            $hasher,
            $security,
            $notifier,
            $lock,
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdUserIds) as $userId) {
            $this->cleanupUser($userId);
        }

        parent::tearDown();
    }

    public function testRequestReissuesHashedTokenAndCompleteRotatesPassword(): void
    {
        $user = $this->newUser(
            'reset',
            'Old-secure-password-123!',
        );

        $this->requestReset->request('missing-'.bin2hex(random_bytes(4)).'@example.test');
        self::assertSame([], $this->queue->drain());

        $firstToken = $this->requestToken($user);
        $reset = $this->pendingReset($user);
        self::assertSame(hash('sha256', $firstToken), $reset->tokenHash());
        self::assertNotSame($firstToken, $reset->tokenHash());

        $secondToken = $this->requestToken($user);
        self::assertNotSame($firstToken, $secondToken);
        self::assertSame(hash('sha256', $secondToken), $reset->tokenHash());
        self::assertNull(
            $this->entityManager
                ->getRepository(PasswordResetToken::class)
                ->findOneBy(['tokenHash' => hash('sha256', $firstToken)]),
        );

        $this->assertResetRejected(
            $firstToken,
            'Ignored-secure-password-789!',
            'El token anterior debe quedar invalidado al reenviar.',
        );
        self::assertSame([], $this->queue->drain());

        $this->completeReset->complete($secondToken, 'New-secure-password-456!');
        self::assertTrue($this->hasher->isPasswordValid($user, 'New-secure-password-456!'));
        self::assertFalse($this->hasher->isPasswordValid($user, 'Old-secure-password-123!'));
        self::assertNotNull($reset->consumedAt());
        self::assertFalse($reset->isUsableAt($reset->expiresAt()->modify('-1 second')));

        $this->assertPasswordChangedMessage('recovery');
        $this->assertResetRejected(
            $secondToken,
            'Another-secure-password-789!',
            'Un token consumido no puede reutilizarse.',
        );
    }

    public function testAuthenticatedChangeRevokesPendingResetAndQueuesConfirmation(): void
    {
        $user = $this->newUser(
            'change',
            'Current-secure-password-123!',
        );
        $this->requestToken($user);
        $reset = $this->pendingReset($user);

        $this->changePassword->change(
            $user,
            'Current-secure-password-123!',
            'Changed-secure-password-456!',
        );

        self::assertTrue($this->hasher->isPasswordValid($user, 'Changed-secure-password-456!'));
        self::assertNotNull($reset->revokedAt());
        $this->assertPasswordChangedMessage('authenticated_change');
    }

    public function testInvalidCurrentPasswordDoesNotMutateCredentialOrPendingReset(): void
    {
        $user = $this->newUser(
            'wrong-current',
            'Current-secure-password-123!',
        );
        $this->requestToken($user);
        $reset = $this->pendingReset($user);
        $originalHash = $user->getPassword();

        try {
            $this->changePassword->change(
                $user,
                'Wrong-current-password-123!',
                'Replacement-secure-password-456!',
            );
            self::fail('La contraseña actual incorrecta debe ser rechazada.');
        } catch (DomainException $exception) {
            self::assertSame('La contraseña actual no es válida.', $exception->getMessage());
        }

        self::assertSame($originalHash, $user->getPassword());
        self::assertTrue($this->hasher->isPasswordValid($user, 'Current-secure-password-123!'));
        self::assertNull($reset->revokedAt());
        self::assertNull($reset->consumedAt());
        self::assertSame([], $this->queue->drain());
    }

    private function newUser(string $prefix, string $password): User
    {
        $user = new User(
            $prefix.'-'.bin2hex(random_bytes(4)).'@example.test',
            'Admin de prueba',
        );
        $user->setPasswordHash($this->hasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->createdUserIds[] = $user->id();

        return $user;
    }

    private function pendingReset(User $user): PasswordResetToken
    {
        $reset = $this->entityManager
            ->getRepository(PasswordResetToken::class)
            ->findOneBy(['user' => $user]);
        self::assertInstanceOf(PasswordResetToken::class, $reset);

        return $reset;
    }

    private function requestToken(User $user): string
    {
        $this->requestReset->request($user->email());
        $message = $this->queue->drain()[0] ?? null;
        self::assertNotNull($message);

        return self::tokenFromResetUrl((string) $message->templateData['reset_url']);
    }

    private function assertPasswordChangedMessage(string $source): void
    {
        $messages = $this->queue->drain();
        self::assertCount(1, $messages);
        self::assertSame('account_password_changed', $messages[0]->templateKey);
        self::assertSame($source, $messages[0]->templateData['source']);
    }

    private function assertResetRejected(
        string $token,
        string $password,
        string $failureMessage,
    ): void {
        try {
            $this->completeReset->complete($token, $password);
            self::fail($failureMessage);
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    private function cleanupUser(string $userId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM condor_platform_audit_event WHERE actor_user_id = :id',
            ['id' => $userId],
        );
        $this->connection->executeStatement(
            'DELETE FROM condor_password_reset WHERE user_id = :id',
            ['id' => $userId],
        );
        $this->connection->executeStatement(
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
