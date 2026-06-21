<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedFilters;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedLimit;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedSort;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\QueryExecutionTrace;
use Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Mother\TraceMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(FingerprintCanonicalizer::class)]
#[CoversClass(QueryExecutionTrace::class)]
#[CoversClass(AppliedFilters::class)]
#[CoversClass(AppliedSort::class)]
#[CoversClass(AppliedLimit::class)]
final class FingerprintCanonicalizerTest extends TestCase
{
    private FingerprintCanonicalizer $canonicalizer;

    #[Override]
    protected function setUp(): void
    {
        $this->canonicalizer = new FingerprintCanonicalizer();
    }

    #[Test]
    public function itBuildsTheDocumentedCanonicalChainForAFilterlessQuery(): void
    {
        $trace = TraceMother::create();

        $this->assertSame($this->chain('[]'), $this->canonicalizer->canonical($trace));
    }

    #[Test]
    public function itSerializesAFilterAsACanonicalJsonEntry(): void
    {
        $trace = TraceMother::create(filters: new AppliedFilters(Filter::contains('name', 'banc')));

        $this->assertSame(
            $this->chain('[{"field":"name","operator":"contains","value":"banc"}]'),
            $this->canonicalizer->canonical($trace),
        );
    }

    #[Test]
    public function itIsDeterministicAcrossRepeatedBuilds(): void
    {
        $one = TraceMother::create(filters: new AppliedFilters(Filter::eq('name', 'BBVA')));
        $two = TraceMother::create(filters: new AppliedFilters(Filter::eq('name', 'BBVA')));

        $this->assertSame($this->canonicalizer->canonical($one), $this->canonicalizer->canonical($two));
    }

    #[Test]
    public function itOrdersFiltersIndependentlyOfTheirReceiptOrder(): void
    {
        $ordered = TraceMother::create(filters: new AppliedFilters(Filter::eq('code', 'X'), Filter::eq('name', 'Y')));
        $shuffled = TraceMother::create(filters: new AppliedFilters(Filter::eq('name', 'Y'), Filter::eq('code', 'X')));

        $this->assertSame($this->canonicalizer->canonical($ordered), $this->canonicalizer->canonical($shuffled));
    }

    #[Test]
    public function itOrdersInListValues(): void
    {
        $ordered = TraceMother::create(filters: new AppliedFilters(Filter::in('name', ['BBVA', 'Caixa'])));
        $shuffled = TraceMother::create(filters: new AppliedFilters(Filter::in('name', ['Caixa', 'BBVA'])));

        $this->assertSame(
            $this->chain('[{"field":"name","operator":"in","value":["BBVA","Caixa"]}]'),
            $this->canonicalizer->canonical($ordered),
        );
        $this->assertSame($this->canonicalizer->canonical($ordered), $this->canonicalizer->canonical($shuffled));
    }

    #[Test]
    public function itOrdersNumericLookingInValuesLexicographicallyAndStably(): void
    {
        // '1e3' and '1000' are numerically equal: under the default SORT_REGULAR
        // they have no defined relative order and PHP's unstable sort could emit
        // either ordering per input, breaking byte-stability. SORT_STRING gives a
        // total lexicographic order ('1000' < '1e3'), so both receipt orderings
        // canonicalize identically.
        $ascending = TraceMother::create(filters: new AppliedFilters(Filter::in('code', ['1e3', '1000'])));
        $descending = TraceMother::create(filters: new AppliedFilters(Filter::in('code', ['1000', '1e3'])));

        $this->assertSame(
            $this->chain('[{"field":"code","operator":"in","value":["1000","1e3"]}]'),
            $this->canonicalizer->canonical($ascending),
        );
        $this->assertSame($this->canonicalizer->canonical($ascending), $this->canonicalizer->canonical($descending));
    }

    #[Test]
    public function itLeavesNonTemporalRangeBoundsAsTheirTrimmedString(): void
    {
        // A range operator can carry a non-datetime bound (e.g. a numeric amount).
        // It matches none of the accepted datetime formats, so normalizeTemporalBound
        // falls back to the trimmed raw string — it never re-validates nor throws.
        $trace = TraceMother::create(filters: new AppliedFilters(Filter::gt('amount', '  100  ')));

        $this->assertSame(
            $this->chain('[{"field":"amount","operator":"gt","value":"100"}]'),
            $this->canonicalizer->canonical($trace),
        );
    }

    #[Test]
    public function itNormalizesTemporalBoundsToUtcAtSecondPrecision(): void
    {
        $trace = TraceMother::create(filters: new AppliedFilters(Filter::gt('createdAt', '2026-06-10T14:30:00+02:00')));

        $this->assertSame(
            $this->chain('[{"field":"createdAt","operator":"gt","value":"2026-06-10T12:30:00+00:00"}]'),
            $this->canonicalizer->canonical($trace),
        );
    }

    #[Test]
    public function itCanonicalizesEqualInstantsInDifferentZonesIdentically(): void
    {
        $offset = TraceMother::create(
            filters: new AppliedFilters(Filter::gte('createdAt', '2026-06-10T14:30:00+02:00')),
        );
        $utc = TraceMother::create(filters: new AppliedFilters(Filter::gte('createdAt', '2026-06-10T12:30:00Z')));

        $this->assertSame($this->canonicalizer->canonical($offset), $this->canonicalizer->canonical($utc));
    }

    #[Test]
    public function itTrimsWhitespaceAroundScalarValues(): void
    {
        $padded = TraceMother::create(filters: new AppliedFilters(Filter::eq('name', '  BBVA  ')));
        $tight = TraceMother::create(filters: new AppliedFilters(Filter::eq('name', 'BBVA')));

        $this->assertSame($this->canonicalizer->canonical($padded), $this->canonicalizer->canonical($tight));
    }

    #[Test]
    public function itDerivesAnXxh128FingerprintOfTheCanonicalString(): void
    {
        $trace = TraceMother::create();

        $fingerprint = $this->canonicalizer->fingerprint($trace);

        $this->assertSame(\hash('xxh128', $this->canonicalizer->canonical($trace)), $fingerprint);
        $this->assertSame(32, \strlen($fingerprint));
        $this->assertTrue(\ctype_xdigit($fingerprint));
    }

    #[Test]
    public function itDistinguishesSortDirection(): void
    {
        $ascending = TraceMother::create(sort: new AppliedSort('name', SortDirection::ASC));
        $descending = TraceMother::create(sort: new AppliedSort('name', SortDirection::DESC));

        $this->assertNotSame(
            $this->canonicalizer->fingerprint($ascending),
            $this->canonicalizer->fingerprint($descending),
        );
    }

    #[Test]
    public function itDistinguishesLimit(): void
    {
        $twentyFive = TraceMother::create(limit: new AppliedLimit(25));
        $twentySix = TraceMother::create(limit: new AppliedLimit(26));

        $this->assertNotSame(
            $this->canonicalizer->fingerprint($twentyFive),
            $this->canonicalizer->fingerprint($twentySix),
        );
    }

    /**
     * The canonical chain for the default trace (`tenant|Bank|<filters>|name|ASC|25`),
     * with only the `filters` JSON segment varying — keeps the literal asserts short.
     */
    private function chain(string $filters): string
    {
        return '__erpify_single_tenant__|Bank|' . $filters . '|name|ASC|25';
    }
}
