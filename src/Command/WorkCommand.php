<?php

declare(strict_types=1);

namespace Pulse\Symfony\Command;

use Pulse\Logs\SpoolHandler;
use Pulse\Symfony\PulseFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:work', description: 'Envoie l’état de l’app à Pulse en continu (sous Supervisor, systemd ou Docker)', aliases: ['pulse-app:work'])]
final class WorkCommand extends Command
{
    public function __construct(private readonly PulseFactory $factory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('for', null, InputOption::VALUE_REQUIRED, 'arrête le worker après ce nombre de secondes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        SpoolHandler::mute();
        $runtime = $this->factory->runtime($this->factory->workerLogger());
        if ($runtime === null) {
            return Command::SUCCESS;
        }
        $probes = $runtime->reporter->probeNames();
        $output->writeln('Pulse surveille l’app' . ($probes === [] ? '.' : ' : ' . implode(', ', $probes) . '.'));
        $for = $input->getOption('for');
        $runtime->worker->run(is_numeric($for) ? (float) $for : null);

        return Command::SUCCESS;
    }
}
