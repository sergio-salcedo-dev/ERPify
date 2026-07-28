<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Tests\Support\AllowlistFile;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins three error-contract drift invariants:
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
 *   (3) The marker → status table on that page MUST list exactly the markers of
 *       `ProblemDetailsFactory::MARKER_STATUS_MAP`. Invariant (2) is deliberately
 *       placement-blind, which is what lets it cover the non-marker files in the
 *       directory — but it is then satisfied by a name appearing in any sentence, and a
 *       status a reader can look up is what the page actually promises. Restricting the
 *       stricter rule to the canonical markers keeps the two from contradicting each
 *       other. It also supplies the direction (2) has no way to see: a row whose marker
 *       has been deleted stays on the page, indistinguishable from a current one.
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
     * A fenced code block, fences included. Stripped before the citation match: a marker whose only
     * appearance on the page is inside a request sample or a TypeScript snippet is illustrated, not
     * documented, and a reader who follows the gate to that block learns nothing about what the marker
     * means or which status it carries.
     */
    private const string FENCED_BLOCK_PATTERN = '/^```.*?^```/ms';

    /**
     * Heading that opens the marker → status table. The page carries other tables whose first column
     * is also a backticked symbol — the test-surface inventory, the body-shape field list — so the row
     * scan is scoped to this section: a name found in any of them documents no status.
     */
    private const string MARKER_TABLE_HEADING = '## Marker interface → HTTP status table';

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
                "%s\nNever cited as `Name` in the prose of %s:\n%s\n"
                . 'Cite each one there, or move it out of api/src/%s — the invariant covers the whole '
                . 'directory, so a file kept in it is public wire surface whether or not it is a marker.',
                self::DOC_CITATION_FAILURE_PREAMBLE,
                self::CONTRACT_DOC_PATH,
                \implode("\n", $undocumented),
                self::MARKER_DIRECTORY,
            ),
        );
    }

    public function testMarkerStatusTableListsExactlyTheCanonicalMarkers(): void
    {
        $this->assertSame(
            $this->canonicalMarkerNames(),
            $this->markerTableRows($this->readContractDoc()),
            \sprintf(
                "%s\nThe table under \"%s\" in %s must hold one row per marker of "
                . 'ProblemDetailsFactory::MARKER_STATUS_MAP and no row for anything else. A missing row '
                . 'leaves a status undocumented; a surplus row is a status a reader would still trust '
                . 'after the marker behind it was gone.',
                self::DOC_CITATION_FAILURE_PREAMBLE,
                self::MARKER_TABLE_HEADING,
                self::CONTRACT_DOC_PATH,
            ),
        );
    }

    public function testFixtureExposesMarkerTableParser(): void
    {
        // Pins the parser against a synthetic page: it reads the marker table's own section only, takes
        // rows whose first cell is a lone backticked token, and ignores the header, the separator, the
        // marker-less catch-all row and every table living under another heading.
        $docBody = <<<'MD'
            ## Marker interface → HTTP status table

            | Marker (`api/src/…`)                | HTTP status | Default `type` |
            |-------------------------------------|-------------|----------------|
            | `NotFound`                          | 404         | `not-found`    |
            | `Conflict`                          | 409         | `conflict`     |
            | Plain `DomainException` (no marker) | 500         | `domain-error` |

            ### Test surface

            | `ProblemDetailsFactoryTest` | full factory contract |
            MD;

        $this->assertSame(
            ['Conflict', 'NotFound'],
            $this->markerTableRows($docBody),
            'Marker-table parser regressed. It must read the marker table only, taking rows whose first '
            . 'cell is a single backticked token and skipping the header, the separator, the marker-less '
            . 'row and every table in another section.',
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
        // Proves the matcher against a synthetic doc, so its decisions stay pinned without depending
        // on the real doc's current wording.
        $docBody = <<<'MD'
            | `Conflict` | 409 | Optimistic-lock and uniqueness refusals |

            `Conflict` is cited a second time here — presence is the contract, not uniqueness.

            Gone appears in bare prose. GoneAway is an unrelated symbol, and a stale
            filename like Gone.php.old is not a citation either.

            ```json
            { "marker": "`Teapot`", "status": 418 }
            ```
            MD;

        $this->assertSame(
            ['Gone', 'Teapot'],
            $this->undocumentedMarkers(['Conflict', 'Gone', 'Teapot'], $docBody),
            'Doc-citation matcher regressed. It must accept a backticked citation in prose (including '
            . 'a repeated one) and reject bare prose, a longer symbol, a stale filename, and a name '
            . 'that appears only inside a fenced code block.',
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
                . 'bind mount the dev overlay declares (compose.dev.yaml). A container started from the '
                . 'prod overlay has no such mount and none is wanted there: this is a dev and CI gate, so '
                . 'run it with ENV unset or ENV=dev.',
                self::DOC_CITATION_FAILURE_PREAMBLE,
                $path,
            ));
        }

        return $body;
    }

    /**
     * A marker counts as documented when the doc's prose cites it as an inline-code token — the form
     * every marker already takes there. A bare substring would be satisfied by an unrelated longer
     * symbol (`GoneAway`) or by a stale filename (`Gone.php.old`), so the backticks are load-bearing,
     * and fenced blocks are cut first so a sample payload cannot stand in for the prose.
     *
     * Presence, not placement: `RateLimitExceeded` is named in the rate-limiting section rather than
     * the marker table, and that is a real citation. Requiring the table would reject it.
     *
     * The doc body is read once by the caller and passed in.
     *
     * @param list<string> $markerNames
     *
     * @return list<string>
     */
    private function undocumentedMarkers(array $markerNames, string $docBody): array
    {
        $prose = \preg_replace(self::FENCED_BLOCK_PATTERN, '', $docBody) ?? $docBody;

        return \array_values(\array_filter(
            $markerNames,
            static fn (string $name): bool => !\str_contains($prose, '`' . $name . '`'),
        ));
    }

    /**
     * Marker names carrying a row in the marker → status table, sorted. A row counts when its first
     * cell is a lone backticked token, which skips the header, the `|---|` separator and the
     * `Plain \`DomainException\` (no marker)` row — that one documents the absence of a marker.
     *
     * @return list<string>
     */
    private function markerTableRows(string $docBody): array
    {
        $names = [];
        $inSection = false;

        foreach (\preg_split('/\R/', $docBody) ?: [] as $line) {
            $trimmed = \trim($line);

            if (\str_starts_with($trimmed, '#')) {
                // Any heading closes the section, so only this table's rows are ever read.
                $inSection = self::MARKER_TABLE_HEADING === $trimmed;

                continue;
            }

            if (!$inSection) {
                continue;
            }

            if (!\str_starts_with($trimmed, '|')) {
                continue;
            }

            $firstCell = \trim(\explode('|', \trim($trimmed, '|'))[0]);

            if (1 === \preg_match('/^`([A-Za-z]+)`$/', $firstCell, $matches)) {
                $names[] = $matches[1];
            }
        }

        \sort($names);

        return $names;
    }

    /**
     * The canonical markers by short name, sorted. Read from `MARKER_STATUS_MAP` because the page
     * itself names that constant as the source of the mapping; deriving the expectation from the
     * directory listing instead would let a marker missing from the constant look documented.
     *
     * @return list<string>
     */
    private function canonicalMarkerNames(): array
    {
        // Two statements rather than `new X()->getConstant()`: PDepend (PHPMD) cannot parse that form.
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $statusMap = $reflectionClass->getConstant('MARKER_STATUS_MAP');

        $this->assertIsArray(
            $statusMap,
            'ProblemDetailsFactory::MARKER_STATUS_MAP must be an array constant for this gate to read it.',
        );

        $names = [];

        foreach (\array_keys($statusMap) as $marker) {
            $names[] = \substr(\strrchr('\\' . $marker, '\\') ?: '', 1);
        }

        \sort($names);

        return $names;
    }
}
