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
    public function itComparesDatesIgnoringSeconds(): void
    {
        $modifier = new DateNodeModifier();

        $this->assertTrue($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 10:00:45')));
        $this->assertFalse($modifier->compare('2026-04-23 10:00:00', new DateTime('2026-04-23 11:00:00')));
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
