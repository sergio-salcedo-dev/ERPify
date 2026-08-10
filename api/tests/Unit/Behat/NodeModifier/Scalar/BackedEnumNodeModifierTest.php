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
    public function itRefusesAReferenceCarryingMoreThanOneCaseSeparator(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected exactly one "::"');

        (new BackedEnumNodeModifier())->getProcessedValue(Role::class . '::ADMIN::VIEWER');
    }

    /**
     * An equality step runs both operands through the modifier its selector names, so with an explicit
     * `field::BackedEnum` suffix the *actual* arrives here as the backing scalar the payload carries.
     * Demanding a resolvable reference of it would make the suffix throw on every payload it exists to
     * read — and in the negative form of a table assertion that throw is read as "did not match", so
     * the step would hold over the very value it forbids.
     */
    #[Test]
    public function itHandsBackABackingScalarUntouchedSoTheExplicitSuffixIsUsable(): void
    {
        $modifier = new BackedEnumNodeModifier();

        $this->assertSame('ADMIN', $modifier->getProcessedValue('ADMIN'));
        $this->assertTrue($modifier->compare('ADMIN', 'ADMIN'));
        $this->assertFalse($modifier->compare('ADMIN', 'VIEWER'));
    }
}
