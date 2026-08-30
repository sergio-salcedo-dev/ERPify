<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Infrastructure;

use Erpify\Shared\Persistence\Infrastructure\AffectedRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

/**
 * The falsifier the seven adapters cannot host. Through Doctrine the non-int branch is unreachable — a DQL
 * bulk `DELETE` returns the driver's `int` — so a test driving a repository can only ever witness the happy
 * path and would be green whatever the narrowing did with the other shapes. Calling the guard directly is
 * what makes the branch reachable, and therefore what makes a mutation of it go red.
 *
 * **The zero case is the assertion this whole change exists for**, not a boundary swept in for completeness:
 * a bulk delete's count is read as erasure evidence, so a legitimate zero must survive untouched while a
 * fabricated one must never be mintable. The two used to be the same value.
 *
 * @internal
 */
#[CoversClass(AffectedRows::class)]
final class AffectedRowsTest extends TestCase
{
    public function testKeepsTheLegitimateZeroTheCallersReadAsEvidence(): void
    {
        $this->assertSame(0, AffectedRows::from(0));
    }

    public function testPassesACountThrough(): void
    {
        $this->assertSame(3, AffectedRows::from(3));
        $this->assertSame(PHP_INT_MAX, AffectedRows::from(PHP_INT_MAX));
    }

    /**
     * The half the type system cannot state. A negative is as impossible as a string for a row count and
     * reaches the same consumers, so the class that decides what may count as a count decides both.
     */
    public function testRaisesOnANegativeCount(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('A bulk statement must yield an affected-row count, got -1.');

        AffectedRows::from(-1);
    }

    /**
     * The numeric string and the float are the cases a lenient narrowing would admit — `is_numeric()`, an
     * `(int)` cast, a `?: 0` fallback each let at least one of them through as a count nothing counted.
     */
    #[DataProvider('provideRaisesOnAnythingThatIsNotACountCases')]
    public function testRaisesOnAnythingThatIsNotACount(mixed $result, string $expectedType): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf(
            'A bulk statement must yield an affected-row count, got %s.',
            $expectedType,
        ));

        AffectedRows::from($result);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideRaisesOnAnythingThatIsNotACountCases(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'numeric string' => ['3', 'string'];
        yield 'float' => [3.0, 'float'];
        yield 'bool' => [true, 'bool'];
        yield 'array of rows' => [[], 'array'];
        yield 'hydrated object' => [new stdClass(), 'stdClass'];
    }
}
