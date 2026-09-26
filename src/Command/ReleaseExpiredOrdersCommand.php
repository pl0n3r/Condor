<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Orders\ExpiredOrderReleaseService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:orders:release-expired',
    description: 'Libera reservas vencidas de pedidos e-commerce pendientes.',
)]
final class ReleaseExpiredOrdersCommand extends Command
{
    public function __construct(
        private readonly ExpiredOrderReleaseService $releaser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Máximo de pedidos a procesar por ejecución.',
            '100',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $rawLimit = (string) $input->getOption('limit');
        if (!ctype_digit($rawLimit)) {
            $output->writeln('<error>--limit debe ser un entero positivo.</error>');

            return Command::INVALID;
        }

        $limit = (int) $rawLimit;
        if ($limit < 1 || $limit > 1000) {
            $output->writeln('<error>--limit debe estar entre 1 y 1000.</error>');

            return Command::INVALID;
        }

        $released = $this->releaser->releaseExpired(
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $limit,
        );
        $output->writeln(sprintf(
            'Reservas e-commerce vencidas liberadas: %d',
            $released,
        ));

        return Command::SUCCESS;
    }
}
