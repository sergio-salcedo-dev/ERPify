<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\ScheduleConsumption\src;

/**
 * A class that only ever TALKS about the attribute. It declares none.
 *
 * Prose like this is ordinary and useful: a command that is not scheduled says so, and says what scheduling it
 * would take — `#[AsSchedule('ghost')]` plus a matching `messenger:consume` receiver in both compose files. A
 * sweep that reads comments turns that sentence into a declaration and demands a transport for a schedule
 * nobody wrote, so the gate goes red over a file that is entirely correct.
 *
 * The bare spelling appears here too, `#[AsSchedule]`, because it is the one that resolves to `default` — the
 * name most likely to collide with a real schedule someone adds later, which is how a false positive becomes
 * a false NEGATIVE: two sources for one name, one of them prose.
 *
 * @internal
 */
final class MentionsTheAttributeInProseFixture
{
}
