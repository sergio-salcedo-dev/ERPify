<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\NodeModifier\Scalar;

use Erpify\Shared\Access\Domain\Role;
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

    /**
     * Resolution must not depend on how the enum is *named*. Locating the case by searching the value
     * for a literal `Enum::` token does exactly that, and not one enum in this repo carries it: the
     * search finds nothing, the miss casts to offset 0, and the case is read from six characters into
     * the FQCN — a name no case matches.
     */
    #[Test]
    public function itResolvesAnEnumWhoseNameDoesNotEndInEnum(): void
    {
        $this->assertSame(Role::ADMIN, (new BackedEnumNodeModifier())->getProcessedValue(Role::class . '::ADMIN'));
    }

    #[Test]
    public function itComparesAResolvedCaseAgainstItsBackingScalar(): void
    {
        $this->assertTrue((new BackedEnumNodeModifier())->compare(Role::class . '::ADMIN', 'ADMIN'));
        $this->assertFalse((new BackedEnumNodeModifier())->compare(Role::class . '::ADMIN', 'VIEWER'));
    }

    #[Test]
    public function itRefusesAValueThatIsNotAFullyQualifiedCaseReference(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected exactly one "::"');

        (new BackedEnumNodeModifier())->getProcessedValue('ADMIN');
    }
}
