<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Scalar;

use Erpify\Tests\Behat\NodeModifier\Scalar\StringNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(StringNodeModifier::class)]
final class StringNodeModifierTest extends TestCase
{
    #[Test]
    public function itCastsScalarsToString(): void
    {
        $modifier = new StringNodeModifier();

        $this->assertSame('123', $modifier->getProcessedValue(123));
        $this->assertSame('123', $modifier->getProcessedValue('123'));
    }

    #[Test]
    public function itMapsNullToAnEmptyString(): void
    {
        $this->assertSame('', (new StringNodeModifier())->getProcessedValue(null));
    }

    #[Test]
    public function itReturnsNullForANonScalarValueInsteadOfAborting(): void
    {
        $modifier = new StringNodeModifier();

        $this->assertNull($modifier->getProcessedValue([]));
        $this->assertNull($modifier->getProcessedValue(new stdClass()));
    }

    #[Test]
    public function itFailsToMatchANonScalarActualAgainstAString(): void
    {
        $this->assertFalse((new StringNodeModifier())->compare('1234567890', []));
    }
}
