<?php

declare(strict_types=1);

namespace Pulse\Symfony\DependencyInjection;

use Pulse\Symfony\Detection\SymfonyProbes;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DetectionPass implements CompilerPassInterface
{
    private const REDIS_CLASSES = ['Redis', 'Relay\Relay', 'Predis\ClientInterface'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SymfonyProbes::class)) {
            return;
        }
        [$redis, $dsns] = $this->redis($container);
        $container->getDefinition(SymfonyProbes::class)->setArguments([
            ServiceLocatorTagPass::register($container, $this->connections($container)),
            ServiceLocatorTagPass::register($container, $redis),
            $dsns,
            ServiceLocatorTagPass::register($container, $this->tagged($container, 'messenger.receiver', 'alias')),
            ServiceLocatorTagPass::register($container, $this->tagged($container, 'scheduler.schedule_provider', 'name')),
        ]);
    }

    /** @return array<string, Reference> */
    private function connections(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return [];
        }
        $out = [];
        foreach ((array) $container->getParameter('doctrine.connections') as $name => $id) {
            if (is_string($id) && $container->has($id)) {
                $out[(string) $name] = new Reference($id);
            }
        }

        return $out;
    }

    // Les connexions des pools de cache n'ont pas de classe Redis à la compilation : leur DSN dira à l'exécution si c'est Redis.
    /** @return array{0: array<string, Reference>, 1: array<string, mixed>} */
    private function redis(ContainerBuilder $container): array
    {
        $services = [];
        $dsns = [];
        foreach ($this->usedCacheConnections($container) as $id) {
            $services[$id] = new Reference($id);
            $dsns[$id] = $container->getDefinition($id)->getArguments()[0] ?? null;
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic() || str_starts_with($id, '.cache_connection.')) {
                continue;
            }
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? '');
            $reflection = is_string($class) && $class !== '' ? $container->getReflectionClass($class, false) : null;
            foreach (self::REDIS_CLASSES as $redisClass) {
                $known = class_exists($redisClass) || interface_exists($redisClass);
                if ($known && $reflection !== null && ($reflection->getName() === $redisClass || $reflection->isSubclassOf($redisClass))) {
                    $services[$id] = new Reference($id);
                    break;
                }
            }
        }

        return [$services, $dsns];
    }

    // FrameworkBundle déclare des connexions par défaut (redis://localhost) même sans pool qui s'en sert : seules celles d'un pool réel comptent.
    /** @return list<string> */
    private function usedCacheConnections(ContainerBuilder $container): array
    {
        $used = [];
        foreach (array_keys($container->findTaggedServiceIds('cache.pool')) as $pool) {
            $definition = $container->getDefinition($pool);
            if ($definition->isAbstract()) {
                continue;
            }
            foreach ($definition->getArguments() as $argument) {
                $id = $argument instanceof Reference ? (string) $argument : '';
                if (str_starts_with($id, '.cache_connection.') && $container->hasDefinition($id)) {
                    $used[$id] = $id;
                }
            }
        }

        return array_values($used);
    }

    /** @return array<string, Reference> */
    private function tagged(ContainerBuilder $container, string $tag, string $attribute): array
    {
        $out = [];
        foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
            foreach ($tags as $attributes) {
                $out[(string) ($attributes[$attribute] ?? $id)] = new Reference($id);
            }
        }

        return $out;
    }
}
