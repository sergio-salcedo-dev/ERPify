<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Date;

use DateTime;
use Erpify\Tests\Behat\NodeModifier\Date\DateNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DateNodeModifier::class)]
final class DateNodeModifierTest extends TestCase
{
    #[Test]
    public function itMatchesTwoInstantsInsideTheToleranceWindow(): void
    {
        $modifier = new DateNodeModifier();

        $this->assertTrue($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 10:00:00')));
        $this->assertTrue($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 10:00:04')));
        $this->assertFalse($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 10:00:05')));
        $this->assertFalse($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 11:00:00')));
    }

    #[Test]
    public function itMatchesTwoInstantsOneSecondApartAcrossAMinuteBoundary(): void
    {
        // A row stamped in the last second of a minute and asserted in the first second of the next.
        $this->assertTrue(
            (new DateNodeModifier())->compare('2026-11-03 13:02:59', new DateTime('2026-11-03 13:03:00')),
        );
    }

    #[Test]
    public function itRefusesTwoInstantsFarApartInsideOneMinute(): void
    {
        $this->assertFalse(
            (new DateNodeModifier())->compare('2026-11-03 13:02:01', new DateTime('2026-11-03 13:02:58')),
        );
    }

    #[Test]
    public function itMatchesNowAgainstAnInstantStampedAMomentAgo(): void
    {
        $this->assertTrue((new DateNodeModifier())->compare('now', new DateTime('-1 second')));
        $this->assertFalse((new DateNodeModifier())->compare('now', new DateTime('-1 minute')));
    }

    #[Test]
    public function itReturnsNullForANonStringNonDateValueInsteadOfAborting(): void
    {
        $modifier = new DateNodeModifier();

        $this->assertNull($modifier->getProcessedValue(123));
        $this->assertNull($modifier->getProcessedValue([]));
    }

    #[Test]
    public function itFailsToMatchANonDateActualAgainstADateString(): void
    {
        $this->assertFalse((new DateNodeModifier())->compare('2026-04-23 10:00:00', 123));
    }

    #[Test]
    public function itDoesNotMatchTwoUnparseableValuesAsEqual(): void
    {
        $modifier = new DateNodeModifier();

        // Both sides collapse to null because neither is a date — that is "unparseable", not
        // "absent", so it must not be reported as a match (a null === null false positive).
        $this->assertFalse($modifier->compare(123, 456));
        $this->assertFalse($modifier->compare([], []));
    }

    #[Test]
    public function itMatchesTwoAbsentValuesButNotAbsentAgainstPresent(): void
    {
        $modifier = new DateNodeModifier();

        $this->assertTrue($modifier->compare(null, null));
        $this->assertFalse($modifier->compare(null, new DateTime('2026-04-23 10:00:00')));
        $this->assertFalse($modifier->compare(new DateTime('2026-04-23 10:00:00'), null));
    }

    #[Test]
    public function itTreatsAnUnparseableDateStringAsNonMatchingInsteadOfThrowing(): void
    {
        $modifier = new DateNodeModifier();

        // An unparseable string is "not a date", same as a non-string actual: it must collapse to
        // null and yield a clean non-match, never an uncaught DateMalformedStringException.
        $this->assertNull($modifier->getProcessedValue('banana'));
        $this->assertFalse($modifier->compare('banana', '2026-04-23 10:00:00'));
        $this->assertFalse($modifier->compare('2026-04-23 10:00:00', 'banana'));
        $this->assertFalse($modifier->compare('banana', 'banana'));
    }
}
