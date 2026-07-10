<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Enum;

use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SessionStatus::class)]
final class SessionStatusTest extends TestCase
{
    /**
     * The backing value is the string persisted in the `status` column, so a silent rename would orphan stored
     * rows while the enum keeps compiling — this pins each state's canonical value.
     */
    #[DataProvider('provideStatusBacksItsExpectedCanonicalValueCases')]
    public function testStatusBacksItsExpectedCanonicalValue(string $expectedValue, SessionStatus $status): void
    {
        $this->assertSame($expectedValue, $status->value);
    }

    /**
     * @return iterable<string, array{string, SessionStatus}>
     */
    public static function provideStatusBacksItsExpectedCanonicalValueCases(): iterable
    {
        yield 'ACTIVE' => ['ACTIVE', SessionStatus::ACTIVE];
        yield 'REVOKED' => ['REVOKED', SessionStatus::REVOKED];
    }

    /**
     * There is deliberately no `EXPIRED` state — caducity is a temporal predicate, not a persisted state — and
     * no value outside the two lifecycle states: a stray token must not resolve to a case.
     *
     * @param non-empty-string $rejected
     */
    #[DataProvider('provideItRejectsAnyValueOutsideTheTwoLifecycleStatesCases')]
    public function testItRejectsAnyValueOutsideTheTwoLifecycleStates(string $rejected): void
    {
        $this->assertNotInstanceOf(SessionStatus::class, SessionStatus::tryFrom($rejected));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideItRejectsAnyValueOutsideTheTwoLifecycleStatesCases(): iterable
    {
        yield 'the deliberately-absent EXPIRED state' => ['EXPIRED'];
        yield 'a lower-cased case value' => ['active'];
        yield 'an unknown token' => ['CLOSED'];
    }
}
