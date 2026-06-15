<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Scalar;

use Erpify\Tests\Behat\NodeModifier\Scalar\IntegerNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(IntegerNodeModifier::class)]
final class IntegerNodeModifierTest extends TestCase
{
    #[Test]
    public function itCoercesScalarsToInt(): void
    {
        $modifier = new IntegerNodeModifier();

        $this->assertSame(42, $modifier->getProcessedValue('42'));
        $this->assertTrue($modifier->compare('42', 42));
    }

    #[Test]
    public function itMapsNullToNull(): void
    {
        $this->assertNull((new IntegerNodeModifier())->getProcessedValue(null));
    }

    #[Test]
    public function itReturnsNullForANonScalarValueInsteadOfAborting(): void
    {
        $modifier = new IntegerNodeModifier();

        $this->assertNull($modifier->getProcessedValue([]));
        $this->assertNull($modifier->getProcessedValue(new stdClass()));
        $this->assertFalse($modifier->compare('42', []));
    }
}
