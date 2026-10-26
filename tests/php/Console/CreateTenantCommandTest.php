<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Application\Onboarding\CreateTenant;
use App\Console\CreateTenantCommand;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateTenantCommandTest extends TestCase
{
    private const PASSWORD_ENV = 'CONDOR_TEST_CREATE_TENANT_PASSWORD_844';
    private const SECRET = 'synthetic-tenant-passphrase-844';

    protected function tearDown(): void
    {
        putenv(self::PASSWORD_ENV);
        parent::tearDown();
    }

    private function options(array $overrides = []): array
    {
        return array_replace([
            '--name' => 'Empresa Demo',
            '--slug' => 'empresa-demo',
            '--legal-name' => 'Empresa Demo SAS',
            '--branch' => 'Sede Central',
            '--email' => 'admin@example.test',
            '--owner-name' => 'Admin Demo',
            '--password-env' => self::PASSWORD_ENV,
        ], $overrides);
    }

    private function command(
        EntityManagerInterface $manager,
        ?ValidatorInterface $validator = null,
        ?UserPasswordHasherInterface $hasher = null,
    ): CreateTenantCommand {
        if ($validator === null) {
            $validator = $this->createMock(ValidatorInterface::class);
            $validator->method('validate')->willReturn(new ConstraintViolationList());
        }

        return new CreateTenantCommand(new CreateTenant(
            $manager,
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            $validator,
            new FunctionalSignalRecorder($manager),
        ));
    }

    public function testAbsentPasswordFailsBeforeAnyPersistence(): void
    {
        putenv(self::PASSWORD_ENV);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $manager->expects(self::never())->method('wrapInTransaction');

        $tester = new CommandTester($this->command($manager));
        self::assertSame(Command::FAILURE, $tester->execute($this->options()));
        self::assertStringContainsString(self::PASSWORD_ENV, $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testInvalidInputIsRejectedWithoutStartingTransaction(): void
    {
        putenv(self::PASSWORD_ENV.'='.self::SECRET);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $manager->expects(self::never())->method('wrapInTransaction');

        $invalid = $this->createMock(ConstraintViolationListInterface::class);
        $invalid->method('count')->willReturn(1);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())->method('validate')->willReturn($invalid);

        $tester = new CommandTester($this->command($manager, $validator));
        self::assertSame(Command::FAILURE, $tester->execute($this->options(['--slug' => 'bad slug'])));
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testExistingSlugRejectsRepeatedRequestsWithoutWrites(): void
    {
        putenv(self::PASSWORD_ENV.'='.self::SECRET);
        $existing = new Tenant('Empresa Existente', 'empresa-demo');
        $tenantRepository = $this->createMock(EntityRepository::class);
        $tenantRepository->expects(self::exactly(2))->method('findOneBy')
            ->with(['slug' => 'empresa-demo'])->willReturn($existing);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(Tenant::class)->willReturn($tenantRepository);
        $manager->expects(self::never())->method('wrapInTransaction');
        $manager->expects(self::never())->method('persist');

        $command = $this->command($manager);
        foreach (range(1, 2) as $attempt) {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute($this->options()));
            self::assertStringContainsString('Ya existe una empresa', $tester->getDisplay());
            self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        }
    }

    public function testDuplicateOwnerEmailAlsoPreventsTransaction(): void
    {
        putenv(self::PASSWORD_ENV.'='.self::SECRET);
        $tenantRepository = $this->createMock(EntityRepository::class);
        $tenantRepository->method('findOneBy')->willReturn(null);
        $ownerRepository = $this->createMock(EntityRepository::class);
        $ownerRepository->method('findOneBy')->willReturn(new User('admin@example.test', 'Existing'));
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn (string $type): EntityRepository => $type === Tenant::class
                ? $tenantRepository : $ownerRepository,
        );
        $manager->expects(self::never())->method('wrapInTransaction');

        $tester = new CommandTester($this->command($manager));
        self::assertSame(Command::FAILURE, $tester->execute($this->options()));
        self::assertStringContainsString('Ya existe una cuenta', $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
    }

    public function testSuccessfulProvisioningReturnsIdsButNeverThePassword(): void
    {
        putenv(self::PASSWORD_ENV.'='.self::SECRET);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::once())->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $transaction): mixed => $transaction());
        $manager->expects(self::exactly(7))->method('persist');
        $manager->expects(self::exactly(2))->method('flush');
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')
            ->with(self::isInstanceOf(User::class), self::SECRET)
            ->willReturn('hash-for-unit-testing');

        $tester = new CommandTester($this->command($manager, null, $hasher));
        self::assertSame(Command::SUCCESS, $tester->execute($this->options()));
        self::assertStringContainsString('Tenant empresa-demo creado.', $tester->getDisplay());
        self::assertStringContainsString('Sede:', $tester->getDisplay());
        self::assertStringContainsString('Propietario:', $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        self::assertStringNotContainsString('hash-for-unit-testing', $tester->getDisplay());
    }
}
