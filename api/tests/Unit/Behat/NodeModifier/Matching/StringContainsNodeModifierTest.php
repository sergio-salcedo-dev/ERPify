<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Matching;

use Erpify\Tests\Behat\NodeModifier\Matching\StringContainsNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(StringContainsNodeModifier::class)]
final class StringContainsNodeModifierTest extends TestCase
{
    #[Test]
    public function itMatchesASubstringCaseInsensitively(): void
    {
        $modifier = new StringContainsNodeModifier();

        $this->assertTrue($modifier->compare('created', 'Successfully Created'));
        $this->assertFalse($modifier->compare('deleted', 'Successfully Created'));
    }

    #[Test]
    public function itJsonEncodesArrayValuesBeforeProbing(): void
    {
        $this->assertTrue((new StringContainsNodeModifier())->compare('a', ['a', 'b']));
    }

    #[Test]
    public function itTreatsANonScalarValueAsAnEmptyHaystackInsteadOfAborting(): void
    {
        $modifier = new StringContainsNodeModifier();

        $this->assertSame('', $modifier->getProcessedValue(new stdClass()));
        $this->assertFalse($modifier->compare('needle', new stdClass()));
    }
}
