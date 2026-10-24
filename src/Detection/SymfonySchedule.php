<?php

declare(strict_types=1);

namespace Pulse\Symfony\Detection;

use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class SymfonySchedule
{
    /**
     * @param ServiceProviderInterface<object>|null $schedules
     * @return list<array{name: string, schedule: string, nextRunAt: ?\DateTimeInterface}>
     */
    public static function snapshots(?ServiceProviderInterface $schedules): array
    {
        if ($schedules === null || !interface_exists(ScheduleProviderInterface::class)) {
            return [];
        }
        $now = new \DateTimeImmutable();
        $out = [];
        foreach (array_keys($schedules->getProvidedServices()) as $scheduleName) {
            $provider = $schedules->get((string) $scheduleName);
            if (!$provider instanceof ScheduleProviderInterface) {
                continue;
            }
            foreach ($provider->getSchedule()->getRecurringMessages() as $recurring) {
                $trigger = $recurring->getTrigger();
                $messageProvider = $recurring->getProvider();
                $name = $messageProvider->getId();
                // Seul un fournisseur statique est lu : un fournisseur à rappel exécuterait du code de l'app.
                if ($messageProvider instanceof StaticMessageProvider) {
                    foreach ($recurring->getMessages(new MessageContext((string) $scheduleName, $recurring->getId(), $trigger, $now)) as $message) {
                        $name = self::nameOf($message);
                        break;
                    }
                }
                $out[] = ['name' => $name, 'schedule' => (string) $trigger, 'nextRunAt' => $trigger->getNextRunDate($now)];
            }
        }

        return $out;
    }

    // Le même nom sert au planning et aux passages : c'est lui qui relie les deux.
    public static function nameOf(object $message): string
    {
        if ($message instanceof \Symfony\Component\Messenger\Message\RedispatchMessage) {
            $inner = $message->envelope;
            $message = $inner instanceof \Symfony\Component\Messenger\Envelope ? $inner->getMessage() : $inner;
        }
        if ($message instanceof \Symfony\Component\Console\Messenger\RunCommandMessage) {
            return $message->input;
        }
        if ($message instanceof \Symfony\Component\Scheduler\Messenger\ServiceCallMessage) {
            $id = $message->getServiceId();
            $short = str_contains($id, '\\') ? substr((string) strrchr($id, '\\'), 1) : $id;

            return $message->getMethod() === '__invoke' ? $short : "{$short}::{$message->getMethod()}";
        }

        return (new \ReflectionClass($message))->getShortName();
    }
}
