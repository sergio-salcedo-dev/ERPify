<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Matching;

use Erpify\Tests\Behat\NodeModifier\Matching\RegexNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RegexNodeModifier::class)]
final class RegexNodeModifierTest extends TestCase
{
    #[Test]
    public function itMatchesAScalarValueAgainstThePattern(): void
    {
        $modifier = new RegexNodeModifier();

        $this->assertTrue($modifier->compare('/^\d+$/', '123'));
        $this->assertTrue($modifier->compare('/^\d+$/', 123));
        $this->assertFalse($modifier->compare('/^\d+$/', '12a'));
    }

    #[Test]
    public function itFailsForANonScalarValueInsteadOfAborting(): void
    {
        $this->assertFalse((new RegexNodeModifier())->compare('/^.*$/', []));
    }

    #[Test]
    public function itFailsForANonStringPatternInsteadOfAborting(): void
    {
        $modifier = new RegexNodeModifier();

        $this->assertFalse($modifier->compare(123, 'value'));
        $this->assertFalse($modifier->compare(null, 'value'));
    }
}
