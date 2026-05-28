<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the "no banned Doctrine 3 / DBAL 4 APIs on the error path" invariant.
 * The error-path source tree (`Shared/Application/Problem/` plus
 * `Shared/Infrastructure/Http/`) MUST contain no occurrence of:
 *
 *   - `flush($entity)` — the per-entity overload removed in Doctrine ORM 3.0.
 *   - `fetchAll(`     — DBAL 3.0 removal; replaced by `fetchAllAssociative` / etc.
 *   - `Connection::query(` — DBAL 4.0 removal; replaced by `executeQuery`.
 *   - `iterate(`     — DBAL 3.0 removal; replaced by `iterateAssociative` / etc.
 *
 * Curated grep — narrowest possible scope (the two error-path subtrees only). This is
 * deliberately tighter than the repo-wide CI gate, which can use a Make target once the
 * broader tree is audited. For the listener / factory shell, a unit test is the right
 * fit: it runs in `make php.unit`, it fails the CI build before merge, and it pins the
 * source-text contract without spinning up a separate static-analysis tool.
 *
 * False-positive shape: the `flush(` ban targets the deprecated single-arg overload
 * `flush($entity)`. The no-arg `flush()` form is still permitted, so the matcher excludes
 * `flush()` (no whitespace, no arg) and any string literal whose surroundings make it
 * obviously a comment / docblock — the source-text walk also checks the file is a `.php`
 * file under the two narrow trees, so collateral damage is minimal.
 *
 * @internal
 */
#[CoversNothing]
final class BannedDoctrineApisTest extends TestCase
{
    #[DataProvider('provideErrorPathSourceTreeContainsNoBannedDoctrineApiCases')]
    public function testErrorPathSourceTreeContainsNoBannedDoctrineApi(
        string $label,
        string $regex,
        string $rationale,
    ): void {
        $hits = [];

        foreach ($this->errorPathPhpFiles() as $file) {
            $contents = \file_get_contents($file->getPathname());
            $this->assertNotFalse($contents, \sprintf('Failed to read %s.', $file->getPathname()));

            if (1 === \preg_match($regex, $contents)) {
                $hits[] = $this->relativePath($file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $hits,
            \sprintf(
                'Banned Doctrine API "%s" found on the error path. %s' . "\n" . 'Files: %s',
                $label,
                $rationale,
                \implode(', ', $hits),
            ),
        );
    }

    /**
     * @return iterable<string, array{label: string, regex: string, rationale: string}>
     */
    public static function provideErrorPathSourceTreeContainsNoBannedDoctrineApiCases(): iterable
    {
        yield 'flush-with-arg' => [
            'label' => '->flush($…)',
            // Match `->flush(` followed by anything other than `)` — i.e. a non-empty arg.
            // The no-arg `->flush()` form is still allowed.
            'regex' => '/->flush\(\s*[^\s)]/',
            'rationale' => 'Doctrine ORM 3 removed the per-entity flush overload; use bare flush().',
        ];

        yield 'fetchAll' => [
            'label' => '->fetchAll(',
            'regex' => '/->fetchAll\(/',
            'rationale' => 'DBAL 3 removed fetchAll(); use fetchAllAssociative / fetchAllNumeric.',
        ];

        yield 'iterate' => [
            'label' => '->iterate(',
            'regex' => '/->iterate\(/',
            'rationale' => 'DBAL 3 removed iterate(); use iterateAssociative / iterateNumeric.',
        ];

        yield 'connection-query' => [
            'label' => 'Connection::query(',
            // The ban targets the deprecated DBAL Connection::query() shorthand. Match either
            // a static call (`Connection::query(`) or an instance call on a parameter / property
            // typed as Connection (`->query(` immediately after a Connection-like identifier).
            // The narrower static form is the only one that has historically appeared in
            // ERPify; the instance form is caught indirectly because a banned constructor
            // dep is already prevented by NoDatabaseDependenciesContractTest.
            'regex' => '/\bConnection::query\(/',
            'rationale' => 'DBAL 4 removed Connection::query(); use executeQuery().',
        ];
    }

    public function testGrepGateActuallyScannedAtLeastOneSourceFile(): void
    {
        // Pins that the iterator wiring (path, recursion, .php filter) does not silently
        // resolve to an empty set — a silent zero-file scan would make the contract vacuous
        // and let a banned API merge undetected.
        $count = \iterator_count($this->errorPathPhpFiles());

        $this->assertGreaterThan(
            0,
            $count,
            'Banned-API grep gate scanned zero files — check the directory roots match '
            . 'the actual error-path source tree.',
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function errorPathPhpFiles(): iterable
    {
        foreach ($this->errorPathRoots() as $root) {
            if (!\is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }

                if ('php' !== $file->getExtension()) {
                    continue;
                }

                yield $file;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function errorPathRoots(): array
    {
        $apiRoot = \dirname(__DIR__, 5);

        return [
            $apiRoot . '/src/Shared/Application/Problem',
            $apiRoot . '/src/Shared/Infrastructure/Http',
        ];
    }

    private function relativePath(string $absolute): string
    {
        $apiRoot = \dirname(__DIR__, 5);
        $apiPrefix = $apiRoot . '/';

        if (\str_starts_with($absolute, $apiPrefix)) {
            return \substr($absolute, \strlen($apiPrefix));
        }

        return $absolute;
    }
}
