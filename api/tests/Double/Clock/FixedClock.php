<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Clock;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
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
 * @internal
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
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
        return $this->now;
    }
}
