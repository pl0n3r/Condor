<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Database\BackupFailure;
use App\Infrastructure\Database\PdoDatabaseBackup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:database:backup-pdo',
    description: 'Crea un backup lógico usando la conexión Doctrine activa.',
)]
final class BackupDatabaseCommand extends Command
{
    public function __construct(private readonly PdoDatabaseBackup $backup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Ruta del archivo SQL de salida.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) ($input->getOption('output') ?? '');
        $diagnosticOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        try {
            $result = $this->backup->write($path);
        } catch (BackupFailure $failure) {
            $diagnosticOutput->writeln(sprintf(
                'backup-database-pdo: stage=%s; %s',
                $failure->stage,
                $failure->safeMessage,
            ));

            return $failure->exitCode;
        } catch (Throwable) {
            $diagnosticOutput->writeln(
                'backup-database-pdo: stage=metadata; '
                .'fallo inesperado; detalles internos redactados.',
            );

            return PdoDatabaseBackup::EXIT_METADATA;
        }

        $diagnosticOutput->writeln(sprintf(
            'backup-database-pdo: %d tablas volcadas y verificadas; '
            .'checksum SHA-256 verificado: %s.',
            $result['tables'],
            $result['checksum'],
        ));

        return Command::SUCCESS;
    }
}
