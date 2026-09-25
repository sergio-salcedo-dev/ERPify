<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Double\Clock;

use DateTimeInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The refusal {@see FixedClock} raises when a test reads an injected instance that disagrees with the ambient
 * clock, and the three shapes it must let through.
 *
 * The double lives under `tests/`, outside the `src` coverage scope, so this credits nothing; what it guards is
 * that the check fires where the rule is broken and stays silent where it is kept — a guard that also fired on
 * the aligned shapes would be switched off by the first test it reddened.
 *
 * @internal
 */
#[CoversNothing]
final class FixedClockTest extends TestCase
{
    private const string INSTANT = '2026-07-10T12:00:00+00:00';

    #[Test]
    public function aClockInstalledAsTheAmbientOneAnswersItsInstant(): void
    {
        $clock = FixedClock::at(self::INSTANT);
        SystemClock::set($clock);

        $this->assertSame(self::INSTANT, $clock->now()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function anInjectedClockAtTheAmbientInstantAnswersEvenWrittenInAnotherZone(): void
    {
        SystemClock::set(FixedClock::at(self::INSTANT));
        $injected = FixedClock::at('2026-07-10T14:00:00+02:00');

        $this->assertSame('2026-07-10T14:00:00+02:00', $injected->now()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function anInjectedClockThatDisagreesWithTheAmbientOneRefusesAndNamesBothInstants(): void
    {
        $injected = FixedClock::at(self::INSTANT);
        $ambient = SystemClock::now()->format('c');

        $this->assertNotSame(self::INSTANT, $ambient, 'the case means nothing unless the suite pin stands elsewhere');

        try {
            $injected->now();
            $this->fail('A FixedClock disagreeing with the ambient clock answered instead of refusing.');
        } catch (LogicException $logicException) {
            $this->assertStringContainsString(self::INSTANT, $logicException->getMessage());
            $this->assertStringContainsString($ambient, $logicException->getMessage());
        }
    }

    #[Test]
    public function advancingTimeBeforeTheSubjectReadsKeepsTheEarlierStamp(): void
    {
        SystemClock::set(FixedClock::at(self::INSTANT));
        $bank = Bank::create('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'JPMorgan Chase', 'JPM');

        SystemClock::set(FixedClock::at('2026-07-10T13:00:00+00:00'));
        $injected = FixedClock::at('2026-07-10T13:00:00+00:00');

        $this->assertSame('2026-07-10T13:00:00+00:00', $injected->now()->format(DateTimeInterface::ATOM));
        $this->assertSame(self::INSTANT, $bank->getCreatedAt()->format(DateTimeInterface::ATOM));
    }

    #[Test]
    public function aDivergenceOfOneMicrosecondStillRefuses(): void
    {
        SystemClock::set(FixedClock::at('2026-07-10T12:00:00.000000+00:00'));
        $injected = FixedClock::at('2026-07-10T12:00:00.000001+00:00');

        $this->expectException(LogicException::class);

        $injected->now();
    }

    #[Test]
    public function aRefusalLeavesTheCheckArmedForTheNextRead(): void
    {
        SystemClock::set(FixedClock::at(self::INSTANT));
        $injected = FixedClock::at('2026-07-10T13:00:00+00:00');

        try {
            $injected->now();
            $this->fail('The first divergent read answered instead of refusing.');
        } catch (LogicException) {
            // The refusal under test is the second one.
        }

        $this->expectException(LogicException::class);

        $injected->now();
    }
}
