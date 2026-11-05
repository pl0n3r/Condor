<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:outbox:dispatch',
    description: 'Entrega los mensajes pendientes del outbox (apto para cron).',
)]
final class OutboxDispatchCommand extends Command
{
    public function __construct(private readonly OutboxDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de mensajes por corrida', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->dispatcher->dispatch((int) $input->getOption('limit'));
        $output->writeln(sprintf(
            'outbox sent=%d deferred=%d failed=%d',
            $result['sent'],
            $result['deferred'],
            $result['failed'],
        ));

        return Command::SUCCESS;
    }
}
