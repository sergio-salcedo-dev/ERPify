<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The query-budget harness drops a query when a class in its backtrace matches a marker by SUBSTRING, and
 * that substring is load-bearing in a direction nothing else watches.
 *
 * Those exclusions are right in themselves — the firewall's per-request identity refresh and the session
 * admission gate's registry read are fixed authentication plumbing, not the business-query behaviour the
 * budgets pin. What is not right is what happens when a SECOND class comes to carry one of the substrings:
 * its queries are dropped too, and a budget that exists to prove a controller did not re-run a lookup keeps
 * passing while the lookup is back. Responses are byte-identical either way — cost is the only signal those
 * scenarios have, and this keeps the cost visible.
 *
 * `UserProvider` is the sharper of the two and is not the one anyone was watching: generic enough that a
 * second implementation is an ordinary thing to add, and excluded from every budget in the suite rather than
 * from one feature's.
 *
 * **The helper list is derived, never hand-kept.** An earlier form named the two helpers literally, which put
 * the whole gate one added exclusion away from silence and made its own "at least two" assertion a tautology
 * over a two-element literal. Reading them out of the harness is what lets that count mean something and what
 * makes a third exclusion covered on the day it is written.
 *
 * **Markers are matched against a PSR-4 pseudo-FQCN, not a basename.** The harness compares
 * `$backtrace['class']`, which carries the namespace: `Erpify\Shared\UserProvider\Resolver` contains the
 * marker while its basename does not, and a basename sweep passed green over exactly that file.
 *
 * **What a green does not prove.** The sweep reads `api/src` only. The exclusion matches any class in the
 * backtrace, so two populations are invisible here: `vendor/` — and `UserProvider` is a Symfony Security
 * naming convention, so its own implementations end in that word — and `api/tests`, which already holds four
 * carriers (the two `UserProvider*Test`s and the two `SessionAdmissionGate*Test`s). A test double is the more
 * likely fifth: `tests/Unit/Doctrine/Stubs/` already keeps `FakeController` and friends, and a
 * `FakeUserProvider` beside them would be the natural way to cover that branch. Neither population is swept,
 * because a test class in a budgeted backtrace is a different shape of problem from a production one.
 *
 * @internal
 */
#[CoversNothing]
final class QueryBudgetExclusionMarkerGateTest extends TestCase
{
    private const string HARNESS = '/tests/Doctrine/TestDebugDataHolder.php';

    public function testEveryQueryExclusionMarkerHasExactlyOneCarrierInTheTree(): void
    {
        $markers = $this->exclusionMarkers();

        $this->assertGreaterThanOrEqual(
            2,
            \count($markers),
            'The harness declares fewer backtrace exclusions than the two this gate was derived against. If '
            . 'one was removed, re-derive the gate rather than leaving it agreeing with a shape nobody uses.',
        );
        $this->assertCount(
            \count($markers),
            \array_unique($markers),
            \sprintf(
                'Two exclusion helpers resolved to the same marker (%s), which means the extraction matched '
                . 'the wrong one and some exclusion is going unwatched.',
                \implode(', ', $markers),
            ),
        );

        foreach ($markers as $helper => $marker) {
            $carriers = $this->carriersOf($marker);

            $this->assertCount(
                1,
                $carriers,
                \sprintf(
                    'Exactly one class under api/src may contain "%s" (excluded by %s): the harness drops the '
                    . 'queries of every class whose FQCN contains it, so a second one makes the per-connection '
                    . 'budgets vacuous in silence. Carriers found: %s.',
                    $marker,
                    $helper,
                    \implode(', ', $carriers) ?: '(none)',
                ),
            );
        }
    }

    /**
     * The markers as the harness spells them, keyed by the helper that owns each — both the helpers and their
     * literals read out of the source, so an added exclusion joins this universe on its own.
     *
     * Bounded to each helper's BODY. An unbounded lazy match slides past the closing brace into the next
     * helper's literal the moment one stops matching, which reports the same marker twice and leaves the
     * other unwatched, green. Both quote styles are accepted for the same reason: the spelling that stops
     * matching must red here, not vanish.
     *
     * @return array<string, string>
     */
    private function exclusionMarkers(): array
    {
        $path = $this->apiRoot() . self::HARNESS;
        $this->assertFileExists($path, 'The query-budget harness moved; re-derive this gate against it.');

        $source = \file_get_contents($path);
        $this->assertIsString($source);

        $found = \preg_match_all(
            '/private function (\w+)\(array \$backtraces\): bool\s*\{(.*?)\n    \}/s',
            $source,
            $helpers,
            PREG_SET_ORDER,
        );

        $this->assertNotFalse($found);
        $this->assertNotSame(
            0,
            $found,
            'No backtrace-exclusion helper was found in the harness. Its shape changed; re-derive this gate '
            . 'against the new one rather than deleting it.',
        );

        $markers = [];

        foreach ($helpers as $helper) {
            if (1 !== \preg_match('/str_contains\(\s*\$class\s*,\s*([\'"])([^\'"]+)\1\s*\)/', $helper[2], $literal)) {
                continue;
            }

            $markers[$helper[1]] = $literal[2];
        }

        return $markers;
    }

    /**
     * Every file under `api/src` whose PSR-4 class name contains the marker. The path stands in for the FQCN
     * the harness actually compares — `src/Shared/UserProvider/Resolver.php` is
     * `Erpify\Shared\UserProvider\Resolver`, a carrier its basename hides.
     *
     * @return list<string>
     */
    private function carriersOf(string $marker): array
    {
        $root = $this->apiRoot();
        $carriers = [];
        $swept = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            ++$swept;
            $relative = \substr($file->getPathname(), \strlen($root . '/src/'));
            $className = \str_replace('/', '\\', \substr($relative, 0, -4));

            if (\str_contains($className, $marker)) {
                $carriers[] = 'src/' . $relative;
            }
        }

        $this->assertGreaterThan(0, $swept, 'The sweep read no PHP under api/src, so it can prove nothing.');
        \sort($carriers);

        return $carriers;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
