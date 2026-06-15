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
}
