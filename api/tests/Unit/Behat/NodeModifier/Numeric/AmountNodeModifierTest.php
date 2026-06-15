<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Numeric;

use Erpify\Tests\Behat\NodeModifier\Numeric\AmountNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(AmountNodeModifier::class)]
final class AmountNodeModifierTest extends TestCase
{
    #[Test]
    public function itComparesEqualAmountsRegardlessOfScale(): void
    {
        $modifier = new AmountNodeModifier();

        $this->assertTrue($modifier->compare('1.5', '1.500000'));
        $this->assertTrue($modifier->compare('1', '1.0'));
        $this->assertFalse($modifier->compare('1.5', '1.6'));
    }

    #[Test]
    public function itTreatsANullActualAsZero(): void
    {
        $this->assertTrue((new AmountNodeModifier())->compare('0', null));
    }

    #[Test]
    public function itFailsForANonNumericOrNonScalarActualInsteadOfAborting(): void
    {
        $modifier = new AmountNodeModifier();

        $this->assertFalse($modifier->compare('1.5', 'abc'));
        $this->assertFalse($modifier->compare('1.5', []));
        $this->assertFalse($modifier->compare('1.5', new stdClass()));
    }

    #[Test]
    public function itProcessesANonNumericValueToNullRatherThanAsserting(): void
    {
        $modifier = new AmountNodeModifier();

        $this->assertNull($modifier->getProcessedValue('abc'));
        $this->assertNull($modifier->getProcessedValue([]));
        $this->assertSame('2.000000000000000000', $modifier->getProcessedValue('2'));
    }
}
