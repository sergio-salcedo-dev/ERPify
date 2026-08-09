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
     * The case name used to be located by searching the value for the literal token `Enum::`, which
     * tied resolution to the enum being *named* with that suffix. Not one enum in this repo is, so a
     * search that found nothing cast to offset 0 and the case was read from six characters into the
     * FQCN — every enum resolving to a name no case matches.
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
