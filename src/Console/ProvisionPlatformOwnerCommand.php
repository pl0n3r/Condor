<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Identity\ProvisionPlatformOwner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:platform-owner:provision',
    description: 'Crea o migra la única cuenta propietaria de la plataforma Condor.',
)]
final class ProvisionPlatformOwnerCommand extends Command
{
    public function __construct(
        private readonly ProvisionPlatformOwner $provisionPlatformOwner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'Correo del propietario de plataforma',
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre visible del propietario',
            )
            ->addOption(
                'password-env',
                null,
                InputOption::VALUE_REQUIRED,
                'Variable de entorno que contiene la contraseña',
                'CONDOR_PLATFORM_OWNER_PASSWORD',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $passwordEnv = (string) $input->getOption('password-env');
        $password = getenv($passwordEnv);

        if (!is_string($password) || $password === '') {
            $io->error(sprintf(
                'Define %s con la contraseña del propietario.',
                $passwordEnv,
            ));

            return Command::FAILURE;
        }

        try {
            $user = $this->provisionPlatformOwner->execute(
                (string) $input->getOption('email'),
                (string) $input->getOption('name'),
                $password,
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Propietario de plataforma aprovisionado: %s.',
            $user->email(),
        ));

        return Command::SUCCESS;
    }
}
