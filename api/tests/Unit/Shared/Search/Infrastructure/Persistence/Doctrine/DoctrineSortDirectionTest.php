<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SortDirection as NativeSortDirection;

/**
 * @internal
 */
#[CoversClass(DoctrineSortDirection::class)]
final class DoctrineSortDirectionTest extends TestCase
{
    /**
     * Pins the pairing rather than merely that a value comes back: an inverted mapping walks every
     * keyset page backwards, and the functional tests that consume it order their own oracle through
     * the same call, so they agree with themselves either way.
     */
    #[DataProvider('provideEachDirectionMapsToItsNativeCaseCases')]
    public function testEachDirectionMapsToItsNativeCase(
        SortDirection $direction,
        NativeSortDirection $expected,
    ): void {
        $this->assertSame($expected, DoctrineSortDirection::from($direction));
    }

    /**
     * @return iterable<string, array{SortDirection, NativeSortDirection}>
     */
    public static function provideEachDirectionMapsToItsNativeCaseCases(): iterable
    {
        yield 'asc' => [SortDirection::ASC, NativeSortDirection::Ascending];
        yield 'desc' => [SortDirection::DESC, NativeSortDirection::Descending];
    }

    /**
     * The universe comes from the enum, never a list written here, and the claim is injectivity:
     * a case added to the search contract without an arm in the mapper raises \UnhandledMatchError
     * before any assertion runs, and two cases collapsing onto one native case — which would make a
     * descending page ascend — fails the count. The provider above sees neither: it was written for
     * the cases that existed.
     */
    public function testEveryDomainCaseMapsToADistinctNativeCase(): void
    {
        $mapped = \array_map(
            static fn (SortDirection $direction): string => DoctrineSortDirection::from($direction)->name,
            SortDirection::cases(),
        );

        $this->assertSame(\array_unique($mapped), $mapped);
    }
}
