<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Clock;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Clock\Domain\SystemClock;
use LogicException;
use Override;

/**
 * The suite's {@see Clock} double: frozen at a preset instant, so a stamped `createdAt`, a computed expiry
 * or an event's `occurredOn` is a value the test chose rather than whatever the wall clock read while the
 * row was written.
 *
 * One class for the whole test tree rather than one per module, and the Rule of Three is the weaker half of
 * the reason. A per-module copy is only worth its duplication while the modules stay apart, and they do not:
 * `Unit/Iam/Identity/…/PruneRetiredSessionsHandlerTest` reaches across a module boundary for the Session
 * module's double, and two tests under `Functional/Iam/Session/` reach across the unit/functional lane for
 * the same one. Copies also drift — of the five that existed, one carried {@see at()} and four did not — and
 * a second spelling of the same idea (`new SymfonyClock(new MockClock(...))`) had grown beside them. That
 * pair stays where the subject IS that adapter:
 * {@see \Erpify\Tests\Unit\Shared\Clock\Infrastructure\SymfonyClockTest} and its neighbours.
 *
 * **It sits under `tests/Double/` and not `tests/Support/`, which is the rule-engine home.**
 * `ArtifactGateSweep` reads an import from `Erpify\Tests\Support\` as one of the two signals that a
 * kernel-free test is an artifact gate, so a double filed there turns every kernel-free test using it into
 * one: four of this tree's tests credit no production class and would need a registry line they have no
 * business carrying. `Erpify\Tests\Double\` is not an engine namespace, so it turns nothing into a gate.
 *
 * A named class rather than an anonymous one, and not only for reuse: PDepend cannot parse a `readonly`
 * anonymous class, so a file containing one is skipped whole by PHPMD — the analyser reports one error and
 * every rule it would have applied to that file silently does not run.
 *
 * **Reading an injected instance that disagrees with the ambient {@see SystemClock} throws.** Aggregates
 * stamp `createdAt`/`updatedAt` off the ambient clock while a use case computes an expiry off the one it was
 * handed, so a test injecting this clock at one instant while the ambient one stands at another builds
 * rows no production run can produce — a session created in 2050 that expires in 2026 — and stays green
 * because nothing compares the two. Seed from the clock the subject reads: install this one with
 * `SystemClock::set()` before the subject runs, or build it from `SystemClock::now()`. A test that advances
 * time installs the later clock before invoking the subject that reads it, and aggregates built earlier keep
 * their earlier stamp.
 *
 * The check runs on READ, not on construction, because only the read tells a divergence apart from a test
 * preparing two instants and advancing between them. It compares instants, so the same moment in two zones
 * passes. When the ambient clock IS a `FixedClock`, consulting it re-enters this method; the static flag
 * lets that nested read answer without checking; the class is not `readonly` because a readonly class cannot
 * declare that static property. It sees only this double: a bare `new DateTimeImmutable()`,
 * `DomainEvent`'s `occurredOn` default, a Symfony `MockClock` handed to a service directly, and the Behat
 * lane all read past it.
 *
 * @internal
 */
final class FixedClock implements Clock
{
    private static bool $consultingAmbient = false;

    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    /**
     * Named constructor from an ISO-8601 string, so a test that needs no date arithmetic can freeze the
     * clock without referencing {@see DateTimeImmutable} itself.
     */
    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        if (!self::$consultingAmbient) {
            self::$consultingAmbient = true;

            try {
                $ambient = SystemClock::now();
            } finally {
                self::$consultingAmbient = false;
            }

            // Epoch seconds with microseconds: the instant, whatever zone either side was written in.
            if ($ambient->format('U.u') !== $this->now->format('U.u')) {
                throw new LogicException(\sprintf(
                    'An injected FixedClock reads %s while the ambient SystemClock reads %s: aggregates would be '
                    . 'stamped by one clock and the subject would compute from the other. Seed from the clock the '
                    . 'subject reads — SystemClock::set() this clock before the subject runs, or build it from '
                    . 'SystemClock::now().',
                    $this->now->format('c'),
                    $ambient->format('c'),
                ));
            }
        }

        return $this->now;
    }
}
