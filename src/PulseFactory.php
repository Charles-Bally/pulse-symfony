<?php

declare(strict_types=1);

namespace Pulse\Symfony;

use Pulse\Config;
use Pulse\Http\Transport;
use Pulse\Logger;
use Pulse\Logs\SpoolHandler;
use Pulse\PackageMeta;
use Pulse\Probe;
use Pulse\Pulse;
use Pulse\Runtime;
use Pulse\Support\Loggers;
use Pulse\Symfony\Detection\SymfonyProbes;
use Pulse\Symfony\Detection\SymfonySchedule;
use Pulse\Versions;
use Symfony\Component\HttpKernel\Kernel;

final class PulseFactory
{
    public const VERSION = '0.0.2';

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $httpProbes
     */
    public function __construct(
        private readonly array $options,
        private readonly string $directory,
        private readonly array $httpProbes,
        private readonly bool $autoDetect,
        private readonly SymfonyProbes $probes,
        private readonly ?object $logger = null,
        private readonly ?Transport $transport = null,
    ) {
    }

    public function config(?Logger $logger = null): ?Config
    {
        return Config::resolve($this->options, null, $logger ?? Loggers::none());
    }

    public function tracker(): Pulse
    {
        $config = $this->config();

        return $config !== null && $config->selection->wants('crons') ? Pulse::in($this->directory) : Pulse::disabled();
    }

    // Sans clé ou sans journaux choisis, le handler reste branché mais ne fait rien : aucun surcoût.
    public function logHandler(): SpoolHandler
    {
        $config = $this->config();

        return $config !== null && $config->logs() ? SpoolHandler::in($this->directory, $config->selection->logLevel) : SpoolHandler::off();
    }

    public function meta(): PackageMeta
    {
        return new PackageMeta('pulse-symfony', Versions::of('pulse-si/symfony', self::VERSION) ?? self::VERSION, 'symfony', Kernel::VERSION);
    }

    public function workerLogger(): Logger
    {
        return $this->logger === null ? Loggers::stderr() : Loggers::both(Loggers::psr($this->logger), Loggers::stderr());
    }

    public function runtime(Logger $logger): ?Runtime
    {
        $runtime = Runtime::create($this->meta(), $this->directory, $this->options, null, $logger, $this->transport);
        if ($runtime === null) {
            return null;
        }
        foreach ($this->httpProbes as $name => $url) {
            $runtime->reporter->addProbe(Probe::http((string) $name, (string) $url));
        }
        if ($this->autoDetect) {
            foreach ($this->probes->detect() as $probe) {
                $runtime->reporter->addProbe($probe);
            }
            $schedules = $this->probes->schedules;
            $runtime->reporter->addCronSource(static fn () => SymfonySchedule::snapshots($schedules));
        }

        return $runtime;
    }
}
