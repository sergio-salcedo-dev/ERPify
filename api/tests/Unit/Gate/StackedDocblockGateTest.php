<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\StackedDocblocks;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * No doc comment anywhere under `api/` may be superseded by another before the declaration, because PHP
 * binds only the last one and the earlier block then documents nothing while reading as if it did. A
 * `@return` on such a block is a type statement over a declaration it does not describe.
 *
 * **Nothing else in the toolchain sees this, and neither does a careful reader.** `php-cs-fixer`'s
 * `phpdoc_to_comment` is the rule that addresses this shape and it is disabled here; turning it on was
 * measured wanting 48 files rewritten — overwhelmingly inline `@var` annotations — while catching none of
 * the instances tried against it. Cost without the effect, so the rule stays off and this gate carries the
 * invariant. Two by-hand sweeps also missed the one instance whose two blocks are separated by an
 * attribute rather than sitting adjacent, which is why the separator set is derived from `getDocComment()`
 * rather than from what a line looks like.
 *
 * The roots are php-cs-fixer's own finder set, so "documented" and "checked" cover the same tree.
 *
 * Rules and their falsification: {@see StackedDocblockRulesGateTest}. This test only applies them to the
 * real tree, and asserts an EMPTY set rather than a count, since the correct inventory is zero.
 *
 * @internal test support
 */
#[CoversNothing]
final class StackedDocblockGateTest extends TestCase
{
    /**
     * Every `api/` directory php-cs-fixer's finder reads, so "formatted" and "checked" cover one tree.
     * `bin` and `features` hold no `.php` today — `bin/console` and `bin/sf` are extensionless — and are
     * swept anyway so that the first one added is covered rather than waiting for someone to notice.
     *
     * @var non-empty-list<string>
     */
    private const array ROOTS = ['src', 'tests', 'config', 'migrations', 'tools', 'bin', 'features', 'public'];

    /**
     * A floor per root, for the roots where an empty sweep would mean the walk broke rather than that the
     * directory is small. It is deliberately NOT a total: `tests` alone holds 1030 files, so any total
     * worth writing stays green with the whole of `src` dropped from the walk — measured, `src` is 680.
     *
     * @var non-empty-array<string, int>
     */
    private const array FLOORS = ['src' => 500, 'tests' => 800, 'migrations' => 10];

    /**
     * Excluded because its whole purpose is to hold the violation: five of its files are deliberate
     * positives, so a sweep that read them would be red by construction and could never go green. The
     * trailing separator is load-bearing — without it a future sibling directory sharing the prefix
     * disappears from the sweep too.
     */
    private const string FIXTURES = 'tests/Unit/Gate/Fixture/StackedDocblocks/';

    #[Test]
    public function noDocblockUnderApiIsSupersededByAnother(): void
    {
        $findings = [];

        foreach (self::ROOTS as $root) {
            foreach ($this->phpFilesIn($root) as $path) {
                foreach (StackedDocblocks::inFile($path) as $line) {
                    $findings[] = \sprintf('%s:%d', $this->relative($path), $line);
                }
            }
        }

        $this->assertSame([], $findings, \sprintf(
            'A doc comment at each site below is followed by another before the declaration, so PHP binds '
            . 'the later one and this block documents nothing. Merge the two, move the block to the '
            . 'declaration it describes, or delete it — decide per site rather than by rule, because all '
            . "three have been the right answer here:\n  %s",
            \implode("\n  ", $findings),
        ));
    }

    /**
     * The sweep must be able to fail, and a per-root floor is what proves it read each one: a root dropped
     * from the walk, or filtered away by an over-broad exclusion, reports the same empty finding list as a
     * clean tree.
     */
    #[Test]
    public function everyRootThatHoldsSourceIsActuallyRead(): void
    {
        foreach (self::FLOORS as $root => $floor) {
            $this->assertGreaterThan($floor, \count($this->phpFilesIn($root)), \sprintf(
                'api/%s contributed fewer files than it holds, so this gate reported on a tree it did not read.',
                $root,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $root): array
    {
        $directory = $this->apiRoot() . '/' . $root;
        $this->assertDirectoryExists($directory, \sprintf('%s is not there, so this sweep read nothing.', $directory));

        $fixtures = $this->apiRoot() . '/' . self::FIXTURES;
        $paths = [];

        /** @var SplFileInfo $file */
        foreach (ApiSourceFiles::phpFiles($directory) as $file) {
            $path = $file->getPathname();

            if (!\str_starts_with($path, $fixtures)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function relative(string $path): string
    {
        return \str_replace($this->apiRoot() . '/', '', $path);
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
