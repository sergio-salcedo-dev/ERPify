<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use Erpify\Tests\Support\AllowlistFile;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins two error-contract drift invariants:
 *
 *   (1) Controllers MUST NOT catch-and-respond with `new JsonResponse(...)`.
 *       Throwing a `DomainException` is the contract — the
 *       {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder}
 *       listener owns response shaping. A regression here would leak ad-hoc body
 *       shapes back into the `/api/*` surface and re-fragment the unified error
 *       wire format.
 *
 *   (2) Every marker exception at any depth under
 *       `api/src/Shared/ErrorContract/Domain/Exception/` MUST be cited in
 *       `docs/api-error-contract.md` as a backticked token (`Forbidden`). That page owns
 *       the marker → status map, so a marker it never names is an undocumented public
 *       wire contract.
 *
 *       Stated over the directory's current contents rather than over a diff: the
 *       invariant then needs no VCS context, holds in any checkout at any clone depth,
 *       and offers nothing to skip. An unreachable doc or an empty marker directory is
 *       a failure, not a pass — either state is precisely when an undocumented marker
 *       would otherwise sail through.
 *
 * Sample drift fixture (single-line, mirrors the legacy Bank controller shape):
 *
 * ```php
 * try { $this->doThing(); }
 * catch (\Throwable $e) { return new JsonResponse(['error' => $e->getMessage()], 500); }
 * ```
 *
 * The matcher walks each `.php` file under `api/src/`, finds every `catch (...) { ... }`
 * statement (whitespace + named-catch tolerant; multi-line tolerant via a small
 * lookahead window), and flags the file when the catch-block body contains
 * `new JsonResponse(`. Files listed in `api/.error-contract-allowlist` are
 * exempt.
 *
 * Failure output:
 *
 *   Controllers must not catch-and-respond. Throw a DomainException instead.
 *   See docs/api-error-contract.md#how-to-add-a-new-error
 *   <relative/path.php>:<line>: <matched code line>
 *   ...
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversNothing]
final class ErrorContractGateTest extends TestCase
{
    /**
     * Failure preamble — kept as a class const so make-target wrappers and CI
     * log scrapers can grep for the literal string.
     */
    public const string FAILURE_PREAMBLE
        = 'Controllers must not catch-and-respond. Throw a DomainException instead. '
        . 'See docs/api-error-contract.md#how-to-add-a-new-error';

    /**
     * Preamble for the second invariant. Separate from {@see FAILURE_PREAMBLE} because the
     * two failures call for different fixes, and a scraper keying on one must not swallow
     * the other.
     */
    public const string DOC_CITATION_FAILURE_PREAMBLE
        = 'Every marker exception must be cited in docs/api-error-contract.md. '
        . 'See docs/api-error-contract.md#marker-interface--http-status-table';

    /**
     * Marker exceptions, relative to `api/src`. Every `.php` in this tree owns a slice of the
     * public wire contract, so the doc-citation invariant covers the whole of it.
     */
    private const string MARKER_DIRECTORY = 'Shared/ErrorContract/Domain/Exception';

    /**
     * A tree whose `.php` files all sit below its top level, so the marker scan's recursion is
     * exercised against a real directory instead of being taken on trust.
     */
    private const string NESTED_SCAN_PROBE_DIRECTORY = 'Shared/ErrorContract';

    /**
     * Relative to the project root — outside `api/`, so in a container it arrives through the
     * read-only `docs/` bind mount declared for the `php` service in `compose.dev.yaml`.
     */
    private const string CONTRACT_DOC_PATH = 'docs/api-error-contract.md';

    /**
     * Lookahead window (lines) when scanning a `catch (...)` body. The catch
     * brace and the offending `new JsonResponse(...)` may be split across
     * several lines — but a 30-line catch block is already a code-smell, so
     * a bounded window keeps the scan O(n) without parsing PHP.
     */
    private const int CATCH_BODY_LOOKAHEAD = 30;

    public function testNoControllerCatchesAndRespondsWithJsonResponse(): void
    {
        $hits = $this->scanForCatchJsonResponseDrift();

        if ([] === $hits) {
            // Empty allowlist filter result is the green path. Pin the assertion count
            // so PHPUnit doesn't flag this as a "risky test" with no assertions.
            $this->addToAssertionCount(1);

            return;
        }

        $message = self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf(
                '%s:%d: %s',
                $hit['file'],
                $hit['line'],
                $hit['code'],
            ),
            $hits,
        ));

        $this->fail($message);
    }

    public function testGateScansAtLeastOneSourceFile(): void
    {
        // Pins that the iterator wiring resolves to a non-empty set — a silent
        // zero-file scan would make the contract vacuous and let drift merge.
        $count = \iterator_count(ApiSourceFiles::phpFiles($this->apiSrcRoot()));

        $this->assertGreaterThan(0, $count, 'Error-contract gate scanned zero files.');
    }

    public function testFixtureExposesGateMatcher(): void
    {
        // Sample drift fixture: an in-memory file scan that proves the matcher
        // catches the canonical shape. Keeps the regex honest without writing
        // to disk and without depending on any production code being broken.
        $driftSource = <<<'PHP'
            <?php
            class Sample {
                public function __invoke() {
                    try {
                        $this->doThing();
                    } catch (\Throwable $e) {
                        return new JsonResponse(['error' => $e->getMessage()], 500);
                    }
                }
            }
            PHP;

        $hits = $this->matchCatchJsonResponseInSource($driftSource);

        $this->assertNotEmpty(
            $hits,
            'Gate matcher failed to flag the canonical catch-and-respond drift fixture — '
            . 'the regex has regressed.',
        );

        $cleanSource = <<<'PHP'
            <?php
            class Sample {
                public function __invoke() {
                    try {
                        $this->doThing();
                    } catch (\Throwable $e) {
                        throw new SomeDomainException('boom', $e);
                    }
                }
            }
            PHP;

        $this->assertSame(
            [],
            $this->matchCatchJsonResponseInSource($cleanSource),
            'Gate matcher flagged a clean throw-DomainException fixture — false positive.',
        );
    }

    public function testAllowlistEntriesPointToExistingFiles(): void
    {
        $apiRoot = $this->apiRoot();
        $missing = [];

        foreach ($this->loadAllowlist() as $relative) {
            $absolute = $apiRoot . '/' . $relative;

            if (!\is_file($absolute)) {
                $missing[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                "Stale entries in api/.error-contract-allowlist (file no longer exists):\n%s",
                \implode("\n", $missing),
            ),
        );
    }

    public function testEveryMarkerIsCitedInTheContractDoc(): void
    {
        $undocumented = $this->undocumentedMarkers($this->markerNames(), $this->readContractDoc());

        $this->assertSame(
            [],
            $undocumented,
            \sprintf(
                "%s\nMarker exception(s) never cited as `Name` in %s:\n%s",
                self::DOC_CITATION_FAILURE_PREAMBLE,
                self::CONTRACT_DOC_PATH,
                \implode("\n", $undocumented),
            ),
        );
    }

    public function testMarkerScanReachesNestedDirectories(): void
    {
        // The marker directory is flat today, so the only way to pin the scan's recursion is to
        // point it at a tree that is not: DomainException sits three levels below this probe, out
        // of reach of a top-level-only scan, which would silently exempt any marker filed in a
        // subdirectory from the citation invariant.
        $names = $this->markerNamesIn($this->apiSrcRoot() . '/' . self::NESTED_SCAN_PROBE_DIRECTORY);

        $this->assertContains(
            'DomainException',
            $names,
            'Marker scan stopped at the top level, so a marker filed in a subdirectory of api/src/'
            . self::MARKER_DIRECTORY . ' would never be checked against the contract doc.',
        );
    }

    public function testFixtureExposesDocCitationMatcher(): void
    {
        // Proves the matcher against a synthetic doc, so its four decisions stay pinned without
        // depending on the real doc's current wording.
        $docBody = <<<'MD'
            | `Conflict` | 409 | Optimistic-lock and uniqueness refusals |

            `Conflict` is cited a second time here — presence is the contract, not uniqueness.

            Gone appears in bare prose. GoneAway is an unrelated symbol, and a stale
            filename like Gone.php.old is not a citation either.
            MD;

        $this->assertSame(
            ['Gone'],
            $this->undocumentedMarkers(['Conflict', 'Gone'], $docBody),
            'Doc-citation matcher regressed. It must accept a backticked citation (including a '
            . 'repeated one) and reject bare prose, a longer symbol and a stale filename.',
        );
    }

    /**
     * @return list<array{file: string, line: int, code: string}>
     */
    private function scanForCatchJsonResponseDrift(): array
    {
        $allowlist = \array_flip($this->loadAllowlist());
        $apiRoot = $this->apiRoot();
        $apiPrefix = $apiRoot . '/';
        $hits = [];

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            $absolute = $file->getPathname();
            $relative = \str_starts_with($absolute, $apiPrefix)
                ? \substr($absolute, \strlen($apiPrefix))
                : $absolute;

            if (isset($allowlist[$relative])) {
                continue;
            }

            $contents = \file_get_contents($absolute);

            if (false === $contents) {
                continue;
            }

            foreach ($this->matchCatchJsonResponseInSource($contents) as $match) {
                $hits[] = [
                    'file' => $relative,
                    'line' => $match['line'],
                    'code' => $match['code'],
                ];
            }
        }

        return $hits;
    }

    /**
     * Scans a PHP source string for `catch (...)` whose body contains
     * `new JsonResponse(`. Returns one hit per offending catch (line of the
     * `new JsonResponse(` token, since that is what the contract bans).
     *
     * Tolerates:
     *   - whitespace variations (`catch  (  Foo  $e  )`)
     *   - named catches (`catch (\Throwable $e)`, `catch (Foo|Bar $e)`)
     *   - multi-line catches (lookahead bounded by self::CATCH_BODY_LOOKAHEAD)
     *
     * Skips lines that are pure single-line comments (// or *) so a comment
     * referencing the pattern (e.g. in a docblock) won't trip the gate.
     *
     * @return list<array{line: int, code: string}>
     */
    private function matchCatchJsonResponseInSource(string $source): array
    {
        $split = \preg_split('/\R/', $source);
        $lines = false === $split ? [] : $split;
        $count = \count($lines);
        $hits = [];

        foreach ($lines as $i => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }

            // catch (...) — anything inside the parens (named, union, leading-backslash).
            if (1 !== \preg_match('/\bcatch\s*\([^)]*\)/', $line)) {
                continue;
            }

            $hit = $this->findJsonResponseInCatchBody($lines, $i, $count);

            if (null !== $hit) {
                $hits[] = $hit;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{line: int, code: string}|null
     */
    private function findJsonResponseInCatchBody(array $lines, int $startIdx, int $count): ?array
    {
        $end = \min($count - 1, $startIdx + self::CATCH_BODY_LOOKAHEAD);

        for ($j = $startIdx; $j <= $end; ++$j) {
            if (!\array_key_exists($j, $lines)) {
                return null;
            }

            $body = $lines[$j];

            if ($this->isCommentLine($body)) {
                continue;
            }

            if (1 === \preg_match('/\bnew\s+JsonResponse\s*\(/', $body)) {
                return [
                    'line' => $j + 1,
                    'code' => \trim($body),
                ];
            }
        }

        return null;
    }

    private function isCommentLine(string $line): bool
    {
        $trimmed = \ltrim($line);

        return \str_starts_with($trimmed, '//')
            || \str_starts_with($trimmed, '*')
            || \str_starts_with($trimmed, '#');
    }

    /**
     * @return list<string>
     */
    private function loadAllowlist(): array
    {
        return AllowlistFile::entries($this->apiRoot() . '/.error-contract-allowlist');
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 5);
    }

    private function apiSrcRoot(): string
    {
        return $this->apiRoot() . '/src';
    }

    private function projectRoot(): string
    {
        return \dirname($this->apiRoot());
    }

    /**
     * @return list<string>
     */
    private function markerNames(): array
    {
        return $this->markerNamesIn($this->apiSrcRoot() . '/' . self::MARKER_DIRECTORY);
    }

    /**
     * Walks the tree, not just its top level. A marker filed one directory deeper is still a
     * marker owning its slice of the wire contract, and a single-level scan would leave it
     * undocumented with the gate reporting green. {@see ApiSourceFiles} is the shared walk the
     * sibling sub-check already uses, so both gates agree on what "every source file" means.
     *
     * @return list<string>
     */
    private function markerNamesIn(string $directory): array
    {
        // Two distinct ways for the scan to come up empty, kept distinguishable: a moved or
        // renamed directory reads very differently from one that lost its files.
        $this->assertDirectoryExists(
            $directory,
            \sprintf(
                "%s\nMarker directory not found at %s — the gate cannot scan what it cannot reach.",
                self::DOC_CITATION_FAILURE_PREAMBLE,
                $directory,
            ),
        );

        $names = [];

        foreach (ApiSourceFiles::phpFiles($directory) as $file) {
            $names[] = $file->getBasename('.php');
        }

        $this->assertNotEmpty(
            $names,
            \sprintf(
                "%s\nMarker directory %s holds no .php file; a zero-marker scan would make this gate vacuous.",
                self::DOC_CITATION_FAILURE_PREAMBLE,
                $directory,
            ),
        );

        // Filesystem iteration order is not guaranteed; sorting keeps a failure listing stable
        // across machines so a rerun is comparable to the run that produced it.
        \sort($names);

        return $names;
    }

    private function readContractDoc(): string
    {
        $path = $this->projectRoot() . '/' . self::CONTRACT_DOC_PATH;
        $body = \is_file($path) ? \file_get_contents($path) : false;

        if (false === $body) {
            // Never a skip: an unreachable doc is exactly the state in which an undocumented
            // marker would sail through, so it has to be as loud as a real violation.
            $this->fail(\sprintf(
                "%s\nThe contract doc is unreadable at %s. An image built from the api/ context does not "
                . 'carry a repo-root doc, so inside a container it arrives only through the read-only docs/ '
                . 'bind mount declared for the php service in compose.dev.yaml.',
                self::DOC_CITATION_FAILURE_PREAMBLE,
                $path,
            ));
        }

        return $body;
    }

    /**
     * A marker counts as documented when the doc cites it as an inline-code token — the form every
     * marker already takes there. A bare substring would be satisfied by an unrelated longer symbol
     * (`GoneAway`) or by a stale filename (`Gone.php.old`), so the backticks are load-bearing.
     * The doc body is read once by the caller and passed in.
     *
     * @param list<string> $markerNames
     *
     * @return list<string>
     */
    private function undocumentedMarkers(array $markerNames, string $docBody): array
    {
        return \array_values(\array_filter(
            $markerNames,
            static fn (string $name): bool => !\str_contains($docBody, '`' . $name . '`'),
        ));
    }
}
