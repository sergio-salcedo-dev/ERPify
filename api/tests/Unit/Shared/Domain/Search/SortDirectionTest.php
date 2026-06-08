<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SortDirection::class)]
final class SortDirectionTest extends TestCase
{
    /**
     * The wire contract is the uppercase backing value (`direction=ASC|DESC`),
     * deliberately distinct from the lowercase filter operators. The token comes
     * in as a plain `string`, so `from()` is a real runtime lookup (not a
     * constant-folded literal) and the assertion genuinely guards the mapping.
     */
    #[DataProvider('wireTokens')]
    public function testEachWireTokenMapsToItsCase(string $token, SortDirection $expected): void
    {
        self::assertSame($expected, SortDirection::from($token));
    }

    /**
     * @return iterable<string, array{string, SortDirection}>
     */
    public static function wireTokens(): iterable
    {
        yield 'asc' => ['ASC', SortDirection::ASC];
        yield 'desc' => ['DESC', SortDirection::DESC];
    }

    #[DataProvider('lowercaseTokens')]
    public function testLowercaseFilterOperatorCasingIsNotASortDirection(string $token): void
    {
        self::assertNull(SortDirection::tryFrom($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lowercaseTokens(): iterable
    {
        yield 'asc' => ['asc'];
        yield 'desc' => ['desc'];
    }
}
