<?php

declare(strict_types=1);

namespace Pulse\Symfony\Detection;

use Pulse\Probe;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class SymfonyProbes
{
    private const ENGINES = [
        'pgsql' => ['postgresql', 'PostgreSQL'],
        'mariadb' => ['mariadb', 'MariaDB'],
        'mysql' => ['mysql', 'MySQL'],
        'sqlite' => ['sqlite', 'SQLite'],
        'sqlsrv' => ['sqlserver', 'SQL Server'],
        'oci' => ['oracle', 'Oracle'],
    ];
    private const COUNT_AWARE = 'Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface';

    /**
     * @param ServiceProviderInterface<object>|null $connections
     * @param ServiceProviderInterface<object>|null $redis
     * @param array<string, mixed> $cacheDsns
     * @param ServiceProviderInterface<object>|null $receivers
     * @param ServiceProviderInterface<object>|null $schedules
     */
    public function __construct(
        private readonly ?ServiceProviderInterface $connections,
        private readonly ?ServiceProviderInterface $redis,
        private readonly array $cacheDsns,
        private readonly ?ServiceProviderInterface $receivers,
        public readonly ?ServiceProviderInterface $schedules,
    ) {
    }

    /** @return list<Probe> */
    public function detect(): array
    {
        $probes = [...$this->databases(), ...$this->redisProbes(), ...$this->queues()];
        $names = [];
        $out = [];
        foreach ($probes as $probe) {
            $count = ($names[$probe->name] ?? 0) + 1;
            $names[$probe->name] = $count;
            $out[] = $count > 1 ? $probe->named("{$probe->name} ({$count})") : $probe;
        }

        return $out;
    }

    // Lectures sans effet de bord seulement : une sonde ne doit rien écrire chez le client.
    /** @return list<Probe> */
    private function databases(): array
    {
        $out = [];
        foreach (array_keys($this->connections?->getProvidedServices() ?? []) as $name) {
            $connection = $this->service($this->connections, (string) $name);
            if ($connection === null || !method_exists($connection, 'getParams')) {
                continue;
            }
            [$engine, $label] = self::engine((array) $connection->getParams());
            $out[] = new Probe($label, 'database', static function () use ($connection): void {
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            }, $engine, static fn () => $connection->close());
        }

        return $out;
    }

    /** @return list<Probe> */
    private function redisProbes(): array
    {
        $servers = [];
        foreach (array_keys($this->redis?->getProvidedServices() ?? []) as $id) {
            $id = (string) $id;
            $identity = $id;
            if (array_key_exists($id, $this->cacheDsns)) {
                $dsn = (string) $this->cacheDsns[$id];
                if (preg_match('#^(redis|rediss|valkey|valkeys):#', $dsn) !== 1) {
                    continue;
                }
                $parts = parse_url(preg_replace('#^[a-z]+:(//)?#', 'redis://', $dsn) ?? $dsn) ?: [];
                $identity = ($parts['host'] ?? $id) . ':' . ($parts['port'] ?? 6379);
            }
            $redis = $this->redis;
            $servers[$identity] ??= new Probe('Redis', 'redis', static fn () => $redis?->get($id)->ping(), 'redis');
        }

        return array_values($servers);
    }

    /** @return list<Probe> */
    private function queues(): array
    {
        $out = [];
        foreach (array_keys($this->receivers?->getProvidedServices() ?? []) as $alias) {
            $alias = (string) $alias;
            if ($alias === 'sync' || str_starts_with($alias, 'scheduler_')) {
                continue;
            }
            $transport = $this->service($this->receivers, $alias);
            if ($transport === null || !is_a($transport, self::COUNT_AWARE) || str_contains($transport::class, 'Amqp')) {
                continue;
            }
            $short = (new \ReflectionClass($transport))->getShortName();
            $engine = strtolower((string) preg_replace('/Transport$/', '', $short)) ?: 'messenger';
            $out[] = new Probe("File {$alias}", 'queue', static fn () => $transport->getMessageCount(), $engine);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string, 1: string}
     */
    private static function engine(array $params): array
    {
        $driver = strtolower((string) ($params['driver'] ?? $params['scheme'] ?? ''));
        if (str_contains(strtolower((string) ($params['serverVersion'] ?? '')), 'mariadb')) {
            $driver = 'mariadb';
        }
        if ($driver === 'mysqli') {
            $driver = 'mysql';
        }
        foreach (self::ENGINES as $needle => $engine) {
            if (str_contains($driver, $needle) || ($needle === 'pgsql' && str_contains($driver, 'postgres'))) {
                return $engine;
            }
        }

        return [$driver !== '' ? $driver : 'dbal', 'Base de données'];
    }

    /** @param ServiceProviderInterface<object>|null $locator */
    private function service(?ServiceProviderInterface $locator, string $id): ?object
    {
        try {
            return $locator?->get($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
