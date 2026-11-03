<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Date;

use DateTime;
use Erpify\Tests\Behat\NodeModifier\Date\SimpleDateNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SimpleDateNodeModifier::class)]
final class SimpleDateNodeModifierTest extends TestCase
{
    #[Test]
    public function itMatchesAnyTwoInstantsOfTheSameDay(): void
    {
        $this->assertTrue((new SimpleDateNodeModifier())->compare('1990-01-15', new DateTime('1990-01-15 23:59:59')));
    }

    #[Test]
    public function itRefusesTheNeighbouringDayEvenSecondsAway(): void
    {
        // The parent's tolerance window must not leak in: one second across midnight is another day.
        $this->assertFalse(
            (new SimpleDateNodeModifier())->compare('1990-01-15 23:59:59', new DateTime('1990-01-16 00:00:00')),
        );
    }

    #[Test]
    public function itKeepsTheParentsGuardsAgainstNonDates(): void
    {
        $modifier = new SimpleDateNodeModifier();

        $this->assertTrue($modifier->compare(null, null));
        $this->assertFalse($modifier->compare('banana', 'banana'));
        $this->assertFalse($modifier->compare('1990-01-15', 123));
    }
}
