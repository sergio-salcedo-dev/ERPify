<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use DateTimeImmutable;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Schedule;

/**
 * What a {@see Schedule} actually emits, flattened to the two facts a schedule encodes: how often, and what.
 *
 * Counting recurring messages — the shape every schedule test here started as — cannot go red on either of
 * them: swapping the payload class or widening `1 day` to `1 year` leaves the count untouched, so the test
 * passes while the schedule stops doing the thing it is named after. Reaching the payload needs a
 * {@see MessageContext}, which is why this lives once here rather than three times in the tests.
 *
 * @internal test support
 */
final class ScheduledTicks
{
    /**
     * @return list<array{trigger: string, message: object}>
     */
    public static function of(Schedule $schedule): array
    {
        $ticks = [];

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $trigger = $recurringMessage->getTrigger();
            // A fixed instant: the trigger is only asked to describe itself, never to compute a run date.
            $context = new MessageContext(
                'test',
                $recurringMessage->getId(),
                $trigger,
                new DateTimeImmutable('@0'),
            );

            foreach ($recurringMessage->getMessages($context) as $message) {
                $ticks[] = ['trigger' => (string) $trigger, 'message' => $message];
            }
        }

        return $ticks;
    }

    /**
     * @return list<string> one `class @ trigger` line per tick, sorted — a stable, readable whole-schedule
     *                      assertion that names both facts at once
     */
    public static function describe(Schedule $schedule): array
    {
        $described = \array_map(
            static fn (array $tick): string => \sprintf('%s @ %s', $tick['message']::class, $tick['trigger']),
            self::of($schedule),
        );

        \sort($described);

        return $described;
    }
}
