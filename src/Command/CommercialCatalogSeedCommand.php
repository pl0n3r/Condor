<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Commercial\CommercialCatalogSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:commercial:seed',
    description: 'Materializa idempotentemente el catálogo comercial canónico.',
)]
final class CommercialCatalogSeedCommand extends Command
{
    public function __construct(
        private readonly CommercialCatalogSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $this->seeder->seed();
        } catch (Throwable) {
            $output->writeln(
                '<error>El catálogo comercial no pudo materializarse.</error>',
            );

            return Command::FAILURE;
        }

        $output->writeln('Catálogo comercial materializado.');

        return Command::SUCCESS;
    }
}
