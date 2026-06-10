<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedFilters;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\FingerprintCanonicalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\QueryExecutionTrace;
use Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Mother\TraceMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The most important test in the system (ADR Structure Patterns).
 *
 * Formal property: the same input (criteria + field maps + entity) produces a
 * byte-for-byte identical canonical {@see QueryExecutionTrace} on every build.
 * The trace is the single point of semantic truth for a cursor's fingerprint, so
 * a drift in how it is BUILT is the only remaining mode of silent cursor
 * corruption — this test turns that into a red CI.
 *
 * Two explicit categories:
 *   (a) positive stability — exact repetition yields identical bytes;
 *   (b) negative drift — representation changes that carry NO semantic meaning
 *       (input filter order, IN-list permutation, value whitespace, datetime
 *       zone/format) canonicalize identically. This is where canonicalization
 *       systems historically fail, so every ordering/normalization invariant of
 *       {@see FingerprintCanonicalizer} has its own negative-drift case here.
 *
 * @internal
 */
#[CoversClass(FingerprintCanonicalizer::class)]
#[CoversClass(QueryExecutionTrace::class)]
final class TraceEquivalenceStabilityTest extends TestCase
{
    private FingerprintCanonicalizer $canonicalizer;

    #[Override]
    protected function setUp(): void
    {
        $this->canonicalizer = new FingerprintCanonicalizer();
    }

    // --- (a) positive stability ----------------------------------------------

    #[Test]
    public function itProducesByteIdenticalCanonicalBytesOnRepeatedBuilds(): void
    {
        $reference = $this->canonicalizer->canonical($this->reference());

        for ($build = 0; $build < 5; ++$build) {
            $this->assertSame($reference, $this->canonicalizer->canonical($this->reference()));
        }
    }

    #[Test]
    public function itProducesAnIdenticalFingerprintOnRepeatedBuilds(): void
    {
        $reference = $this->canonicalizer->fingerprint($this->reference());

        for ($build = 0; $build < 5; ++$build) {
            $this->assertSame($reference, $this->canonicalizer->fingerprint($this->reference()));
        }
    }

    // --- (b) negative drift --------------------------------------------------

    #[Test]
    public function negativeDriftInputFilterOrderIsCanonicallyIrrelevant(): void
    {
        $this->assertCanonicallyEquivalentToReference(new AppliedFilters(
            Filter::gte('createdAt', '2026-01-01T00:00:00Z'),
            Filter::in('status', ['active', 'pending']),
            Filter::contains('name', 'banc'),
        ));
    }

    #[Test]
    public function negativeDriftInListPermutationIsCanonicallyIrrelevant(): void
    {
        $this->assertCanonicallyEquivalentToReference(new AppliedFilters(
            Filter::contains('name', 'banc'),
            Filter::in('status', ['pending', 'active']),
            Filter::gte('createdAt', '2026-01-01T00:00:00Z'),
        ));
    }

    #[Test]
    public function negativeDriftValueWhitespaceIsCanonicallyIrrelevant(): void
    {
        $this->assertCanonicallyEquivalentToReference(new AppliedFilters(
            Filter::contains('name', '  banc  '),
            Filter::in('status', ['active', '  pending  ']),
            Filter::gte('createdAt', '2026-01-01T00:00:00Z'),
        ));
    }

    #[Test]
    public function negativeDriftDatetimeZoneAndFormatIsCanonicallyIrrelevant(): void
    {
        // 2026-01-01T01:00:00+01:00 is the same instant as 2026-01-01T00:00:00Z.
        $this->assertCanonicallyEquivalentToReference(new AppliedFilters(
            Filter::contains('name', 'banc'),
            Filter::in('status', ['active', 'pending']),
            Filter::gte('createdAt', '2026-01-01T01:00:00+01:00'),
        ));
    }

    #[Test]
    public function combinedDriftAcrossEveryInvariantIsCanonicallyIrrelevant(): void
    {
        $this->assertCanonicallyEquivalentToReference(new AppliedFilters(
            Filter::gte('createdAt', '2026-01-01T01:00:00+01:00'),
            Filter::in('status', ['  pending  ', 'active']),
            Filter::contains('name', '  banc  '),
        ));
    }

    // --- guard against a vacuous equivalence ---------------------------------

    #[Test]
    public function aGenuineSemanticChangeDoesChangeTheCanonicalBytes(): void
    {
        $changed = $this->traceWith(new AppliedFilters(
            Filter::contains('name', 'banco'), // 'banc' → 'banco' is a real semantic difference
            Filter::in('status', ['active', 'pending']),
            Filter::gte('createdAt', '2026-01-01T00:00:00Z'),
        ));

        $this->assertNotSame($this->canonicalizer->canonical($this->reference()), $this->canonicalizer->canonical($changed));
    }

    private function assertCanonicallyEquivalentToReference(AppliedFilters $drifted): void
    {
        $this->assertSame($this->canonicalizer->canonical($this->reference()), $this->canonicalizer->canonical($this->traceWith($drifted)));
    }

    private function reference(): QueryExecutionTrace
    {
        return $this->traceWith(new AppliedFilters(
            Filter::contains('name', 'banc'),
            Filter::in('status', ['active', 'pending']),
            Filter::gte('createdAt', '2026-01-01T00:00:00Z'),
        ));
    }

    private function traceWith(AppliedFilters $filters): QueryExecutionTrace
    {
        return TraceMother::create(filters: $filters);
    }
}
