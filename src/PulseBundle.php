<?php

declare(strict_types=1);

namespace Pulse\Symfony;

use Pulse\Http\Transport;
use Pulse\Pulse;
use Pulse\Symfony\Command\ReportCommand;
use Pulse\Symfony\Command\WorkCommand;
use Pulse\Symfony\DependencyInjection\DetectionPass;
use Pulse\Symfony\Detection\ScheduledRunSubscriber;
use Pulse\Symfony\Detection\SymfonyProbes;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Scheduler\Event\PostRunEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class PulseBundle extends AbstractBundle
{
    public const LOG_HANDLER = 'pulse.log_handler';
    private const TEXT_OPTIONS = [
        'key', 'endpoint', 'send', 'include', 'exclude', 'logs', 'log_level',
        'public_key', 'clear', 'relay_fingerprint', 'app_version', 'disabled',
    ];

    protected string $extensionAlias = 'pulse';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();
        $children = $root->children();
        foreach (self::TEXT_OPTIONS as $option) {
            $children->scalarNode($option)->defaultNull()->end();
        }
        $children->booleanNode('auto_detect')->defaultTrue()->end();
        $children->arrayNode('http_probes')->useAttributeAsKey('name')->scalarPrototype()->end()->end();
        $children->scalarNode('directory')->defaultValue('%kernel.project_dir%/var/pulse')->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set(SymfonyProbes::class)->args([null, null, [], null, null]);
        $services->set(PulseFactory::class)->args([
            array_intersect_key($config, array_flip(self::TEXT_OPTIONS)),
            $config['directory'],
            $config['http_probes'],
            $config['auto_detect'],
            service(SymfonyProbes::class),
            service('logger')->nullOnInvalid(),
            service(Transport::class)->nullOnInvalid(),
        ]);
        $services->set(Pulse::class)->factory([service(PulseFactory::class), 'tracker'])->public();
        $services->set(self::LOG_HANDLER, \Pulse\Logs\SpoolHandler::class)->factory([service(PulseFactory::class), 'logHandler']);
        $services->set(WorkCommand::class)->args([service(PulseFactory::class)])->tag('console.command', ['command' => 'pulse:work|pulse-app:work']);
        $services->set(ReportCommand::class)->args([service(PulseFactory::class)])->tag('console.command', ['command' => 'pulse:report|pulse-app:report']);
        if (class_exists(PostRunEvent::class)) {
            $services->set(ScheduledRunSubscriber::class)->args([service(Pulse::class)])->tag('kernel.event_subscriber');
        }
    }

    // Le handler passe après ceux de l'app (priorité basse) et ne garde jamais un message pour lui.
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('monolog')) {
            return;
        }
        $builder->prependExtensionConfig('monolog', ['handlers' => ['pulse' => [
            'type' => 'service',
            'id' => self::LOG_HANDLER,
            'priority' => -100,
            'channels' => ['!event'],
        ]]]);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DetectionPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }
}
