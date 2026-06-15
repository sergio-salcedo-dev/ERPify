<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Scalar;

use Erpify\Tests\Behat\NodeModifier\Scalar\BackedEnumNodeModifier;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BackedEnumNodeModifier::class)]
final class BackedEnumNodeModifierTest extends TestCase
{
    #[Test]
    public function itThrowsADescriptiveFailureForANonStringExpectedRatherThanAborting(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected a "Fqcn::CASE" string, got int');

        (new BackedEnumNodeModifier())->getProcessedValue(123);
    }
}
