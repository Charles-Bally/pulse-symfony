<?php

declare(strict_types=1);

namespace Pulse\Symfony\Command;

use Pulse\Logs\SpoolHandler;
use Pulse\Support\Loggers;
use Pulse\Symfony\PulseFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'pulse:report', description: 'Envoie un seul rapport à Pulse (essai, cron)', aliases: ['pulse-app:report'])]
final class ReportCommand extends Command
{
    public function __construct(private readonly PulseFactory $factory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        SpoolHandler::mute();
        $runtime = $this->factory->runtime(Loggers::stderr());
        if ($runtime === null) {
            return Command::FAILURE;
        }
        $ack = $runtime->reporter->reportNow();
        $report = $runtime->reporter->lastReport() ?? ['checks' => [], 'crons' => []];
        foreach ($report['checks'] as $check) {
            $output->writeln(sprintf('  %s %s (%d ms)%s', $check['ok'] ? 'ok    ' : 'échec ', $check['name'], $check['latencyMs'], isset($check['error']) ? " : {$check['error']}" : ''));
        }
        $output->writeln(sprintf('  %d tâche(s) planifiée(s)', count($report['crons'])));
        if ($ack === null) {
            $output->writeln('<error>Rapport non accepté par Pulse.</error>');

            return Command::FAILURE;
        }
        $runtime->shipper?->flush();
        $output->writeln(sprintf('<info>Rapport accepté par Pulse, prochain dans %d s.</info>', $runtime->worker->state->intervalSeconds()));

        return Command::SUCCESS;
    }
}
