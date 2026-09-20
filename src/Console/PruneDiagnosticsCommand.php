<?php

declare(strict_types=1);

namespace App\Console;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnostics:prune',
    description: 'Elimina diagnósticos y enlaces compartidos vencidos según retención.',
)]
final class PruneDiagnosticsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Días de retención de incidentes',
            '14',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var(
            $input->getOption('days'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 90]],
        );

        if (!is_int($days)) {
            $io->error('La retención debe estar entre 1 y 90 días.');

            return Command::FAILURE;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->sub(new DateInterval('P'.$days.'D'));

        $expiredShares = $this->connection->executeStatement(
            'DELETE FROM condor_diagnostic_share '
            .'WHERE expires_at < :now OR revoked_at IS NOT NULL',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
        $incidents = $this->connection->executeStatement(
            'DELETE FROM condor_error_incident WHERE occurred_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );

        $io->success(sprintf(
            'Purgados %d enlaces y %d incidentes antiguos.',
            $expiredShares,
            $incidents,
        ));

        return Command::SUCCESS;
    }
}
