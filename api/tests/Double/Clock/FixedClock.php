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
 * One class for the whole test tree, and the reason is not only the Rule of Three. Five identical copies
 * lived one per module under the claim that each kept its module's tests free of another module's helpers,
 * and that claim was already false: `Unit/Iam/Identity/…/PruneRetiredSessionsHandlerTest` and two tests
 * under `Functional/Iam/Session/` imported the Session module's copy. What the split bought was drift —
 * only one of the five carried the {@see at()} constructor — and a third way of spelling the same thing
 * (`new SymfonyClock(new MockClock(...))`) growing beside them. The Symfony pair stays where the subject IS
 * that adapter: {@see \Erpify\Tests\Unit\Shared\Clock\Infrastructure\SymfonyClockTest} and its neighbours.
 *
 * It sits under `tests/Double/` and not `tests/Support/`, which is the rule-engine home: `ArtifactGateSweep`
 * treats an import from `Erpify\Tests\Support\` as one of the two signals that a kernel-free test is an
 * artifact gate, so filing a double there made two session contract tests unclassifiable and reddened
 * `ArtifactGatePlacementGateTest` — measured, not foreseen.
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
