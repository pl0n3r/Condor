<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Application\Identity\ProvisionPlatformOwner;
use App\Console\ProvisionPlatformOwnerCommand;
use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProvisionPlatformOwnerCommandTest extends TestCase
{
    private const ENV = 'CONDOR_TEST_PLATFORM_OWNER_PASSWORD_845';
    private const PASSWORD = 'synthetic-test-password-123456';


    private function command(
        EntityManagerInterface $entityManager,
        ?UserPasswordHasherInterface $hasher = null,
    ): ProvisionPlatformOwnerCommand {
        return new ProvisionPlatformOwnerCommand(new ProvisionPlatformOwner(
            $entityManager,
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
        ));
    }

    private function runCommand(
        CommandTester $tester,
        string $email = 'owner@example.test',
        string $name = 'Owner',
    ): int {
        return $tester->execute([
            '--email' => $email,
            '--name' => $name,
            '--password-env' => self::ENV,
        ]);
    }

    protected function tearDown(): void
    {
        putenv(self::ENV);
        parent::tearDown();
    }

    public function testMissingOrEmptyPasswordEnvironmentFailsWithoutAccessingStorage(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $command = $this->command($entityManager);
        foreach ([false, ''] as $value) {
            $value === false ? putenv(self::ENV) : putenv(self::ENV.'='.$value);
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $this->runCommand($tester, 'owner@example.test', 'Owner'));
            self::assertStringContainsString('Define '.self::ENV, $tester->getDisplay());
            self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        }
    }

    public function testInvalidEmailNameAndShortPasswordFailBeforeDatabaseAccess(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $command = $this->command($entityManager);
        $cases = [
            ['bad-email', 'Owner', self::PASSWORD, 'correo del propietario no es válido'],
            ['owner@example.test', ' ', self::PASSWORD, 'nombre del propietario es obligatorio'],
            ['owner@example.test', 'Owner', 'too-short', 'al menos 12 caracteres'],
        ];
        foreach ($cases as [$email, $name, $password, $message]) {
            putenv(self::ENV.'='.$password);
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $this->runCommand($tester, $email, $name));
            self::assertStringContainsString($message, $tester->getDisplay());
            self::assertStringNotContainsString($password, $tester->getDisplay());
        }
    }

    public function testNewOwnerIsProvisionedAndPasswordIsNeverPrinted(): void
    {
        putenv(self::ENV.'='.self::PASSWORD);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('fetchAssociative')
            ->willReturn(['user_id' => null]);
        $connection->expects(self::once())->method('executeStatement')
            ->with(
                self::stringContains('UPDATE condor_platform_owner'),
                self::callback(static fn (array $params): bool =>
                    $params['singleton_key'] === 1 && is_string($params['user_id'])
                    && strlen($params['user_id']) > 0),
            )->willReturn(1);
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->willReturn(null);
        $repository->expects(self::once())->method('findAll')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')
            ->with(self::callback(static fn (User $user): bool =>
                $user->email() === 'owner@example.test'
                && $user->displayName() === 'Owner'));
        $entityManager->expects(self::exactly(2))->method('flush');
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())->method('hashPassword')
            ->with(self::isInstanceOf(User::class), self::PASSWORD)
            ->willReturn('fake-hash-without-the-plaintext');
        $tester = new CommandTester($this->command($entityManager, $passwordHasher));
        self::assertSame(Command::SUCCESS, $this->runCommand($tester, ' OWNER@EXAMPLE.TEST ', 'Owner'));
        self::assertStringContainsString('owner@example.test', $tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        self::assertStringNotContainsString('fake-hash', $tester->getDisplay());
    }

    public function testRepeatedProvisioningKeepsTheSameOwnerAndRotatesHash(): void
    {
        putenv(self::ENV.'='.self::PASSWORD);
        $owner = new User('owner@example.test', 'Owner', [User::ROLE_LEGACY_SUPER_ADMIN]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::exactly(2))->method('fetchAssociative')
            ->willReturn(['user_id' => $owner->id()]);
        $connection->expects(self::exactly(2))->method('commit');
        $connection->expects(self::never())->method('executeStatement');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('find')
            ->with($owner->id())->willReturn($owner);
        $repository->expects(self::never())->method('findOneBy');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $hashCalls = 0;
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::exactly(2))->method('hashPassword')
            ->willReturnCallback(static function (User $user, string $plain) use (&$hashCalls): string {
                TestCase::assertSame(self::PASSWORD, $plain);
                ++$hashCalls;

                return 'synthetic-hash-'.$hashCalls;
            });
        $command = $this->command($entityManager, $hasher);
        foreach (range(1, 2) as $attempt) {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $this->runCommand($tester, $owner->email(), 'Updated Owner'));
            self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
            self::assertSame('synthetic-hash-'.$attempt, $owner->getPassword());
            self::assertTrue($owner->hasRole(User::ROLE_PLATFORM_OWNER));
            self::assertFalse($owner->hasRole(User::ROLE_LEGACY_SUPER_ADMIN));
        }
    }

    public function testSecondOwnerIsRejectedAndTransactionRollsBackWithoutLeakingPassword(): void
    {
        putenv(self::ENV.'='.self::PASSWORD);
        $owner = new User('first@example.test', 'First');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('fetchAssociative')
            ->willReturn(['user_id' => $owner->id()]);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($owner);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');
        $tester = new CommandTester($this->command($entityManager, $hasher));
        self::assertSame(Command::FAILURE, $this->runCommand($tester, 'second@example.test', 'Second'));
        self::assertStringContainsString('propietario de plataforma distinto', $tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testDatabaseFailureReturnsFailureWithoutPrintingPassword(): void
    {
        putenv(self::ENV.'='.self::PASSWORD);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')
            ->willThrowException(new \RuntimeException('Synthetic storage is temporarily unavailable'));
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');
        $tester = new CommandTester($this->command($entityManager, $hasher));
        self::assertSame(Command::FAILURE, $this->runCommand($tester, 'owner@example.test', 'Owner'));
        self::assertStringContainsString('Synthetic storage is temporarily unavailable', $tester->getDisplay());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }
}
