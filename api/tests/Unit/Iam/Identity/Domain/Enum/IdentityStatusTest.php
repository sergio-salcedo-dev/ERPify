<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Enum;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IdentityStatus::class)]
final class IdentityStatusTest extends TestCase
{
    /**
     * The backing value is the string persisted in the `status` column, so a silent rename would orphan
     * stored rows while the enum keeps compiling — this pins each state's canonical value.
     */
    #[DataProvider('provideStatusBacksItsExpectedCanonicalValueCases')]
    public function testStatusBacksItsExpectedCanonicalValue(string $expectedValue, IdentityStatus $status): void
    {
        $this->assertSame($expectedValue, $status->value);
    }

    /**
     * @return iterable<string, array{string, IdentityStatus}>
     */
    public static function provideStatusBacksItsExpectedCanonicalValueCases(): iterable
    {
        yield 'INVITED' => ['INVITED', IdentityStatus::INVITED];
        yield 'ACTIVE' => ['ACTIVE', IdentityStatus::ACTIVE];
        yield 'SUSPENDED' => ['SUSPENDED', IdentityStatus::SUSPENDED];
        yield 'DEACTIVATED' => ['DEACTIVATED', IdentityStatus::DEACTIVATED];
    }

    /**
     * The admission model deliberately has no `PENDING` state (invitation, not a pending self-registration,
     * is how an identity comes into being) and no other value outside the four lifecycle states: a stray
     * token must not resolve to a case. The input arrives as a plain `string` so the rejection is a real
     * runtime check, not one the type-checker folds away.
     *
     * @param non-empty-string $rejected
     */
    #[DataProvider('provideItRejectsAnyValueOutsideTheFourLifecycleStatesCases')]
    public function testItRejectsAnyValueOutsideTheFourLifecycleStates(string $rejected): void
    {
        $this->assertNotInstanceOf(IdentityStatus::class, IdentityStatus::tryFrom($rejected));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideItRejectsAnyValueOutsideTheFourLifecycleStatesCases(): iterable
    {
        yield 'the retired PENDING state' => ['PENDING'];
        yield 'a lower-cased case value' => ['active'];
        yield 'an unknown token' => ['ARCHIVED'];
    }
}
