<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Matching;

use Erpify\Tests\Behat\NodeModifier\Matching\OneOfNodeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OneOfNodeModifier::class)]
final class OneOfNodeModifierTest extends TestCase
{
    #[Test]
    public function itMatchesWhenTheValueIsInTheCommaSeparatedList(): void
    {
        $modifier = new OneOfNodeModifier();

        $this->assertTrue($modifier->compare('active,pending,archived', 'pending'));
        $this->assertFalse($modifier->compare('active,pending,archived', 'deleted'));
    }

    #[Test]
    public function itFailsForANonScalarExpectedInsteadOfAborting(): void
    {
        $this->assertFalse((new OneOfNodeModifier())->compare([], 'pending'));
    }
}
