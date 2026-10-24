<?php

declare(strict_types=1);

namespace Pulse\Symfony\Detection;

use Pulse\Pulse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;

final class ScheduledRunSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Pulse $pulse)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PostRunEvent::class => 'onPostRun', FailureEvent::class => 'onFailure'];
    }

    public function onPostRun(PostRunEvent $event): void
    {
        $this->pulse->recordCronRun(SymfonySchedule::nameOf($event->getMessage()), true);
    }

    public function onFailure(FailureEvent $event): void
    {
        $error = $event->getError();
        if ($error instanceof \Symfony\Component\Messenger\Exception\WrappedExceptionsInterface) {
            $wrapped = array_values($error->getWrappedExceptions());
            $error = $wrapped[0] ?? $error;
        }
        $this->pulse->recordCronRun(SymfonySchedule::nameOf($event->getMessage()), false, $error);
    }
}
