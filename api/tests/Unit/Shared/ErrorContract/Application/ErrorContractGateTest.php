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
 *   (2) Adding a new marker exception under `api/src/Shared/ErrorContract/Domain/Exception/`
 *       without updating `docs/api-error-contract.md` in the same change is a
 *       documentation-freshness regression. Implemented as a git-aware
 *       sub-check that no-ops when no merge base is reachable (e.g. detached
 *       HEAD on a tag in CI), to avoid false positives outside PR context.
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

    public function testNewMarkerExceptionWithoutDocsUpdateIsRejected(): void
    {
        // Git-aware sub-check. Skipped when git context isn't usable
        // (detached tag build, missing merge base, sandbox without git binary).
        $base = $this->resolveGitBase();

        if (null === $base) {
            $this->markTestSkipped(
                'Doc-freshness check skipped: no usable git merge base '
                . '(set ERROR_CONTRACT_GATE_BASE=<sha> to override).',
            );
        }

        $apiRoot = $this->apiRoot();

        $addedMarkers = $this->gitAddedFiles(
            $base,
            $apiRoot,
            'src/Shared/ErrorContract/Domain/Exception/',
        );

        if ([] === $addedMarkers) {
            // No marker added on this branch — nothing to enforce.
            $this->addToAssertionCount(1);

            return;
        }

        $docChanged = $this->gitFileChanged(
            $base,
            $this->projectRoot(),
            'docs/api-error-contract.md',
        );

        $this->assertTrue(
            $docChanged,
            \sprintf(
                "%s\nAdded marker exception(s) without updating docs/api-error-contract.md:\n%s",
                self::FAILURE_PREAMBLE,
                \implode("\n", $addedMarkers),
            ),
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
     * Resolves the git base ref to diff against. Order:
     *   1. `ERROR_CONTRACT_GATE_BASE` env var (CI override).
     *   2. `origin/main` if reachable.
     *   3. `main` if reachable.
     *   4. `null` → caller skips the check.
     */
    private function resolveGitBase(): ?string
    {
        $override = \getenv('ERROR_CONTRACT_GATE_BASE');

        if (\is_string($override) && '' !== $override) {
            return $this->gitMergeBase($override);
        }

        foreach (['origin/main', 'main'] as $candidate) {
            $base = $this->gitMergeBase($candidate);

            if (null !== $base) {
                return $base;
            }
        }

        return null;
    }

    private function gitMergeBase(string $ref): ?string
    {
        $cwd = $this->projectRoot();
        $output = $this->runGit($cwd, ['merge-base', 'HEAD', $ref]);

        if (null === $output) {
            return null;
        }

        $sha = \trim($output);

        return '' === $sha ? null : $sha;
    }

    /**
     * @return list<string>
     */
    private function gitAddedFiles(string $base, string $cwd, string $pathPrefix): array
    {
        $output = $this->runGit($cwd, [
            'diff',
            '--diff-filter=A',
            '--name-only',
            $base . '...HEAD',
            '--',
            $pathPrefix,
        ]);

        if (null === $output) {
            return [];
        }

        $files = [];

        foreach (\preg_split('/\R/', $output) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            // Only PHP files matching the prefix; the diff already filters by path.
            if (\str_ends_with($trimmed, '.php')) {
                $files[] = $trimmed;
            }
        }

        return $files;
    }

    private function gitFileChanged(string $base, string $cwd, string $relativePath): bool
    {
        $output = $this->runGit($cwd, [
            'diff',
            '--name-only',
            $base . '...HEAD',
            '--',
            $relativePath,
        ]);

        if (null === $output) {
            return false;
        }

        return '' !== \trim($output);
    }

    /**
     * @param list<string> $args
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function runGit(string $cwd, array $args): ?string
    {
        if (!\is_dir($cwd . '/.git') && !\is_file($cwd . '/.git')) {
            return null;
        }

        $command = 'git ' . \implode(' ', \array_map(\escapeshellarg(...), $args));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];

        $process = \proc_open($command, $descriptors, $pipes, $cwd, [
            'GIT_OPTIONAL_LOCKS' => '0',
            'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
        ]);

        if (!\is_resource($process)) {
            return null;
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }

        $stdout = isset($pipes[1]) && \is_resource($pipes[1])
            ? \stream_get_contents($pipes[1])
            : false;

        if (isset($pipes[1]) && \is_resource($pipes[1])) {
            \fclose($pipes[1]);
        }

        if (isset($pipes[2]) && \is_resource($pipes[2])) {
            \fclose($pipes[2]);
        }

        $exit = \proc_close($process);

        return (0 !== $exit || false === $stdout) ? null : $stdout;
    }
}
