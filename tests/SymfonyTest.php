<?php

declare(strict_types=1);

namespace Pulse\Symfony\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\LoggerInterface;
use Pulse\Http\Response;
use Pulse\Http\Transport;
use Pulse\Logs\LogSpool;
use Pulse\Logs\SpoolHandler;
use Pulse\Pulse;
use Pulse\Storage\StateStore;
use Pulse\Support\Loggers;
use Pulse\Symfony\PulseBundle;
use Pulse\Symfony\PulseFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

final class SymfonyTest extends KernelTestCase
{
    public const KEY = 'pk_test_8f2aQx3f9aB7cD1eF5gH9iJ2kL4mN6oP8qR';

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    // Le conteneur compilé reste chargé d'un test à l'autre : le dossier est commun et remis à vide.
    protected function setUp(): void
    {
        TestKernel::$directory = sys_get_temp_dir() . '/pulse-symfony-' . getmypid();
        foreach (['state.json', 'logs.ndjson', 'crons.json'] as $file) {
            if (is_file(TestKernel::$directory . "/{$file}")) {
                file_put_contents(TestKernel::$directory . "/{$file}", '');
            }
        }
        self::bootKernel();
    }

    public function testRegistersTheBundleAndItsLogHandler(): void
    {
        $container = self::getContainer();
        self::assertInstanceOf(Pulse::class, $container->get(Pulse::class));
        $handlers = $container->get('monolog.logger')->getHandlers();
        self::assertInstanceOf(SpoolHandler::class, end($handlers));
        self::assertGreaterThan(1, count($handlers));
    }

    public function testDetectsDoctrineRedisAndMessenger(): void
    {
        $runtime = self::getContainer()->get(PulseFactory::class)->runtime(Loggers::none());
        self::assertSame(['SQLite', 'Redis', 'File async'], $runtime?->reporter->probeNames());
        $checks = array_column($runtime->reporter->buildReport()['checks'], null, 'name');
        self::assertSame([true, 'database', 'sqlite'], [$checks['SQLite']['ok'], $checks['SQLite']['kind'], $checks['SQLite']['engine']]);
        self::assertSame([true, 'queue', 'doctrine'], [$checks['File async']['ok'], $checks['File async']['kind'], $checks['File async']['engine']]);
        self::assertFalse($checks['Redis']['ok']);
    }

    public function testReadsTheSchedulerAndRecordsRuns(): void
    {
        $container = self::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        $schedule = new TestSchedule();
        $context = new MessageContext('default', 'x', new CronExpressionTrigger(), new \DateTimeImmutable());
        $dispatcher->dispatch(new PostRunEvent($schedule, $context, new RelanceAbonnements(), null));
        $failure = new HandlerFailedException(new Envelope(new NettoyageDisque()), ['App\\Handler::__invoke' => new \RuntimeException('disque plein token=abc')]);
        $dispatcher->dispatch(new FailureEvent($schedule, $context, new NettoyageDisque(), $failure));
        self::assertSame(3, $container->get(Pulse::class)->track('rapport-hebdo', static fn () => 3));

        $runtime = $container->get(PulseFactory::class)->runtime(Loggers::none());
        $crons = array_column($runtime?->reporter->buildReport()['crons'] ?? [], null, 'name');
        self::assertSame(['0 * * * *', true], [$crons['RelanceAbonnements']['schedule'], $crons['RelanceAbonnements']['lastRunOk']]);
        self::assertArrayHasKey('nextRunAt', $crons['RelanceAbonnements']);
        self::assertSame(['every 10 seconds', false, 'disque plein token=***'], [
            $crons['NettoyageDisque']['schedule'], $crons['NettoyageDisque']['lastRunOk'], $crons['NettoyageDisque']['error'],
        ]);
        self::assertTrue($crons['rapport-hebdo']['lastRunOk']);
    }

    public function testCopiesLogsOnlyOnceTheOfferAllowsIt(): void
    {
        $logger = self::getContainer()->get(LoggerInterface::class);
        $logger->error('avant l’accord de Pulse');
        self::assertSame([], LogSpool::in(TestKernel::$directory)->drain());
        (new StateStore(TestKernel::$directory . '/state.json'))->write(true, false, 60);
        $logger->error('Paiement refusé token=abc123');
        $entries = LogSpool::in(TestKernel::$directory)->drain();
        self::assertSame(['ERROR', 'app', 'Paiement refusé token=***'], [$entries[0]['level'], $entries[0]['context'], $entries[0]['message']]);
        self::assertStringContainsString('Paiement refusé token=abc123', (string) file_get_contents(TestKernel::$directory . '/test.log'));
    }

    public function testCommandsSendTheReport(): void
    {
        $application = new Application(self::$kernel);
        $report = new CommandTester($application->find('pulse:report'));
        self::assertSame(0, $report->execute([]));
        $transport = self::getContainer()->get(Transport::class);
        [$url, $headers, $body] = $transport->calls[0];
        self::assertSame(['https://api.pulse-app.si/ingest/report', self::KEY], [$url, $headers['x-pulse-key']]);
        $sent = json_decode($body, true);
        self::assertSame(['pulse-symfony', \Pulse\Symfony\PulseFactory::VERSION, 'symfony', Kernel::VERSION], [$sent['packageName'], $sent['packageVersion'], $sent['framework'], $sent['frameworkVersion']]);
        self::assertStringContainsString('Rapport accepté par Pulse', $report->getDisplay());

        $transport->calls = [];
        self::assertSame(0, (new CommandTester($application->find('pulse-app:work')))->execute(['--for' => '2.5']));
        self::assertCount(1, $transport->calls);
    }
}

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public static string $directory = '';

    public function __construct()
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new DoctrineBundle(), new PulseBundle()];
    }

    public function getCacheDir(): string
    {
        return self::$directory . '/cache';
    }

    public function getLogDir(): string
    {
        return self::$directory;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'nimbus',
            'http_method_override' => false,
            'messenger' => ['transports' => ['async' => 'doctrine://default?auto_setup=1', 'sync' => 'sync://']],
        ]);
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:']]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'stream', 'path' => self::$directory . '/test.log', 'level' => 'debug']]]);
        $container->extension('pulse', ['key' => SymfonyTest::KEY, 'directory' => self::$directory]);
        $services = $container->services();
        $services->set(Transport::class, RecordingTransport::class)->public();
        $services->set(TestSchedule::class)->tag('scheduler.schedule_provider', ['name' => 'default']);
        $services->set('app.redis', \Redis::class);
    }
}

final class TestSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('0 * * * *', new RelanceAbonnements()),
            RecurringMessage::every('10 seconds', new NettoyageDisque()),
        );
    }
}

final class RelanceAbonnements
{
}

final class NettoyageDisque
{
}

final class RecordingTransport implements Transport
{
    /** @var list<array{0: string, 1: array<string, string>, 2: string}> */
    public array $calls = [];

    public function post(string $url, array $headers, string $body): Response
    {
        $this->calls[] = [$url, $headers, $body];

        return new Response(202, '{"accepted":true,"intervalSeconds":30,"live":false,"logs":false}');
    }
}
