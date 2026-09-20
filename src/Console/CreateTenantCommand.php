<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Onboarding\CreateTenant;
use App\Application\Onboarding\CreateTenantInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:onboarding:create', description: 'Crea un tenant con razón social, sede principal y propietario.')]
final class CreateTenantCommand extends Command
{
    public function __construct(private readonly CreateTenant $createTenant)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nombre de la empresa')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Identificador URL')
            ->addOption('legal-name', null, InputOption::VALUE_REQUIRED, 'Razón social')
            ->addOption('nit', null, InputOption::VALUE_OPTIONAL, 'NIT')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Nombre de la sede principal', 'Principal')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Correo del propietario')
            ->addOption('owner-name', null, InputOption::VALUE_REQUIRED, 'Nombre del propietario')
            ->addOption('password-env', null, InputOption::VALUE_REQUIRED, 'Variable de entorno que contiene la contraseña', 'CONDOR_OWNER_PASSWORD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $passwordEnv = (string) $input->getOption('password-env');
        $password = getenv($passwordEnv);

        if (!is_string($password) || $password === '') {
            $io->error(sprintf('Define la variable de entorno %s con una contraseña de al menos 12 caracteres.', $passwordEnv));
            return Command::FAILURE;
        }

        try {
            $result = $this->createTenant->execute(new CreateTenantInput(
                (string) $input->getOption('name'),
                (string) $input->getOption('slug'),
                (string) $input->getOption('legal-name'),
                $input->getOption('nit') !== null ? (string) $input->getOption('nit') : null,
                (string) $input->getOption('branch'),
                (string) $input->getOption('email'),
                (string) $input->getOption('owner-name'),
                $password,
            ));
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Tenant %s creado. Sede: %s. Propietario: %s.', $result->tenantSlug, $result->branchId, $result->ownerUserId));

        return Command::SUCCESS;
    }
}
