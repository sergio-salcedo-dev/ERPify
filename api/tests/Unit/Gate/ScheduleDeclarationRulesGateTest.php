<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ScheduleConsumption;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the half of the gate that reads PHP: which schedules the tree declares, and which
 * transport Symfony mints for each. {@see ScheduleConsumptionGateTest} runs against the real tree, which is
 * green by construction once the wiring is right, so on its own it cannot tell a rule that works from one
 * that answers "nothing declared" no matter what it is shown.
 *
 * Both directions of this half cost something. A spelling the sweep misses ships a transport nobody consumes
 * with the gate still green — the failure it exists to refuse, one level up. A name the sweep invents out of
 * prose demands a transport nobody wrote, and the bare spelling resolves to `default`, so a docblock can
 * quietly supply a name a real schedule later collides with.
 *
 * @internal
 */
#[CoversNothing]
final class ScheduleDeclarationRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ScheduleConsumption';

    #[Test]
    public function itSeesEverySpellingOfTheAttributeSymfonyAccepts(): void
    {
        // Positional, bare (which Symfony resolves to `default`), named-argument and fully qualified. A
        // sweep that saw only the first would let the other three ship a transport nobody consumes while
        // this gate stayed green — the exact failure it exists to refuse, one level up.
        $this->assertSame(
            ['alpha', 'default', 'fqcn', 'named'],
            ScheduleConsumption::declaredScheduleNames(self::FIXTURES . '/src'),
        );
    }

    #[Test]
    public function itIgnoresTheAttributeMentionedOnlyInAComment(): void
    {
        // A docblock that names the attribute describes an intention, and an intention must not be able to
        // stand in for a declaration — the same rule the person-reference gate applies to its wiring check.
        // Read as raw bytes, a command explaining that it is NOT scheduled becomes a schedule, and the gate
        // demands a transport nobody wrote.
        //
        // `ghost` is the whole witness, and it is enough: the fixture's prose also carries the bare spelling,
        // but a sweep that reads comments contributes BOTH, so this one assertion goes red on the mechanism
        // either way. What it cannot see is the other direction the fixture warns about — prose contributing
        // a name a real schedule also declares. `declaredScheduleNames()` deduplicates before returning, so
        // two sources for one name are indistinguishable from one by construction, at any level above it.
        $this->assertNotContains('ghost', ScheduleConsumption::declaredScheduleNames(self::FIXTURES . '/src'));
    }

    #[Test]
    public function itDerivesTheTransportSymfonyCreates(): void
    {
        $this->assertSame('scheduler_alpha', ScheduleConsumption::transportOf('alpha'));
    }
}
