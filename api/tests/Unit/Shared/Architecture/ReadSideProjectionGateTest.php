<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static guardrail for the read-side projection lifecycle (docs/rules/read-side-projections.md). A
 * projection policy ("enricher") is the right tool only while its derived attributes stay
 * low-cardinality and single-aggregate. This gate mechanizes the two invariants that are statically
 * visible and leaves the rest to review:
 *
 *   Invariant 1 (cardinality) — an aggregate must not accrete more than MAX_DERIVED_ATTRIBUTES
 *     non-persisted derived attributes. Each is materialized at read time through one
 *     `assign<Name>()` mutator, so the mutator count bounds the derived-attribute cardinality
 *     without a full attribute parse. Crossing it means the entity is becoming a projection
 *     carrier (a DTO disguised as an entity) → promote to a read-model DTO.
 *
 *   Invariant 4 (single-aggregate scope) — a projection policy must not be shared across module
 *     boundaries. General cross-context import enforcement is owned by BoundedContextGateTest (an
 *     enricher imported outside its module is a gated import); this gate only closes the escape
 *     hatch: a projection policy may never be ALLOWLISTED as a cross-context seam in
 *     api/.bounded-context-allowlist. If another module needs the derived data, promote it to a
 *     published read-model — do not widen the seam.
 *
 * Invariants 2 (query participation), 3 (freshness SLA) and 5 (temporal fan-out) are semantic /
 * runtime concerns invisible to static analysis and stay review-only.
 *
 * @internal
 */
#[CoversNothing]
final class ReadSideProjectionGateTest extends TestCase
{
    /**
     * Max non-persisted derived attributes an aggregate may carry. Each is materialized at read time
     * through one `assign<Name>()` mutator, so the mutator count is the cardinality. Raising this is a
     * deliberate, reviewable decision — past a small count the honest model is a read-model DTO.
     */
    private const int MAX_DERIVED_ATTRIBUTES = 1;

    /** Read-side projection policy classes carry this short-name suffix by convention. */
    private const string PROJECTION_POLICY_SUFFIX = 'Enricher';

    public function testAggregatesDoNotAccreteDerivedProjectionAttributes(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            if (!\str_contains(\str_replace('\\', '/', $file->getPathname()), '/Domain/Entity/')) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            ++$scanned;
            $count = \preg_match_all('/\bfunction\s+assign[A-Z]\w*\s*\(/', $contents);

            if (\is_int($count) && $count > self::MAX_DERIVED_ATTRIBUTES) {
                $offenders[] = \sprintf('%s (%d derived attributes)', $file->getFilename(), $count);
            }
        }

        $this->assertGreaterThan(0, $scanned, 'Projection gate scanned zero Domain/Entity files.');
        $this->assertSame(
            [],
            $offenders,
            \sprintf(
                'An aggregate may carry at most %d non-persisted derived attribute(s) (a read-time '
                . '`assign<Name>()` mutator). Past that it is a projection carrier — promote to a '
                . "read-model DTO (docs/rules/read-side-projections.md, invariant 1):\n%s",
                self::MAX_DERIVED_ATTRIBUTES,
                \implode("\n", $offenders),
            ),
        );
    }

    public function testProjectionPoliciesAreNotAllowlistedAsCrossContextSeams(): void
    {
        $leaked = \array_values(\array_filter(
            $this->allowlistTargets(),
            static fn (string $target): bool => \str_ends_with($target, self::PROJECTION_POLICY_SUFFIX),
        ));

        $this->assertSame(
            [],
            $leaked,
            \sprintf(
                'A read-side projection policy (`*%s`) must never be an allowlisted cross-context '
                . 'seam: sharing it across modules breaks single-aggregate scope. Promote the derived '
                . 'data to a published read-model instead (docs/rules/read-side-projections.md, '
                . "invariant 4):\n%s",
                self::PROJECTION_POLICY_SUFFIX,
                \implode("\n", $leaked),
            ),
        );
    }

    /**
     * Right-hand-side targets of `=>` seams (including `* => X` global seams) in the allowlist.
     * Comment / blank lines and whole-file exemptions (no `=>`, hence no target) are skipped.
     *
     * @return list<string>
     */
    private function allowlistTargets(): array
    {
        $path = \dirname(__DIR__, 4) . '/.bounded-context-allowlist';
        $raw = \is_file($path) ? \file_get_contents($path) : false;

        if (false === $raw) {
            return [];
        }

        $targets = [];

        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (\str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!\str_contains($trimmed, '=>')) {
                continue;
            }

            $targets[] = \trim(\explode('=>', $trimmed, 2)[1]);
        }

        return $targets;
    }
}
