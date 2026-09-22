<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ClassImports;
use Erpify\Tests\Support\OrderByArguments;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * An ORM query builder is handed a sort direction, never a string.
 *
 * doctrine/orm 3.7 deprecated the `'ASC'`/`'DESC'` string its `orderBy()`/`addOrderBy()` took and
 * removes it in 4, where a survivor stops being a deprecation and becomes a `TypeError`. Converting
 * the eleven live sites was not the hard part; keeping the twelfth from being written is, and
 * nothing in the toolchain does it. `failOnDeprecation` is the only detector, the Behat lane has
 * none, and PHPStan sees a signature that still accepts `string`. Worse, doctrine/deprecations
 * dedupes **by link** and all four ORM order-by deprecations share one, so a single process reports
 * at most ONE such site however many are live — which is exactly why the conversion found one
 * reported against eleven live, and why a second site added beside a first is invisible.
 *
 * **DBAL is separated by a mechanism, not an allowlist.** `Doctrine\DBAL\Query\QueryBuilder` still
 * declares `?string $order` and deprecates nothing, so its callers must keep passing the string;
 * handing it the enum is a `TypeError` the other way. A file that imports it is therefore exempt,
 * and the second assertion is what stops that exemption being a hole: no file may import both
 * builders, so the exemption can never cover an ORM call. Measured on this tree, one file imports
 * DBAL's and none imports both.
 *
 * The third assertion exists because the first is a no-violations claim, and those pass loudest when
 * the sweep has stopped reaching anything: a narrowed root, a renamed directory or a parser that
 * returns nothing all read as success. It pins a floor on the call sites actually seen, in both
 * `src` and `tests`.
 *
 * **What a green does not prove.** {@see OrderByArguments} reads the argument's own top level, so a
 * string reached through a call, held in a variable or built by concatenation passes; none occurs
 * here. It reads the ORM builder's two methods only, so a direction set through `Expr\OrderBy` or a
 * raw DQL string is outside it. And it judges a file by what it imports, so a builder obtained from
 * a collaborator whose file imports neither is read as ORM — conservative, which is the safe
 * direction.
 *
 * @internal
 */
#[CoversNothing]
final class OrmSortDirectionGateTest extends TestCase
{
    private const string DBAL_QUERY_BUILDER = \Doctrine\DBAL\Query\QueryBuilder::class;

    private const string ORM_QUERY_BUILDER = \Doctrine\ORM\QueryBuilder::class;

    /**
     * Floors, not counts: a count rewards the defect and refuses the repair. These are the smallest
     * numbers that make a vacuous sweep impossible, well under what the tree holds.
     */
    private const int MINIMUM_CALL_SITES = 8;

    private const int MINIMUM_FILES_SWEPT = 500;

    #[Test]
    public function noOrmQueryBuilderIsHandedAStringSortDirection(): void
    {
        $offences = [];

        foreach ($this->sweep() as $relative => $source) {
            if ($this->imports($source, self::DBAL_QUERY_BUILDER)) {
                continue;
            }

            foreach (OrderByArguments::callsIn($source) as $call) {
                if (!$call['carriesString']) {
                    continue;
                }

                $offences[] = \sprintf(
                    '%s:%d passes `%s` to %s(); doctrine/orm 3.7 deprecates the string and 4 removes it. '
                    . 'Pass PHP\'s SortDirection — `NativeSortDirection::Ascending`, or '
                    . 'DoctrineSortDirection::from() when you hold the domain enum.',
                    $relative,
                    $call['line'],
                    $call['text'],
                    $call['method'],
                );
            }
        }

        $this->assertSame([], $offences, \implode("\n", $offences));
    }

    /**
     * The exemption above is keyed on a file importing DBAL's query builder. That is only sound while
     * no file holds both, so this is the assertion that keeps it sound rather than convenient.
     */
    #[Test]
    public function noFileImportsBothQueryBuilders(): void
    {
        $both = [];

        foreach ($this->sweep() as $relative => $source) {
            if (
                $this->imports($source, self::DBAL_QUERY_BUILDER)
                && $this->imports($source, self::ORM_QUERY_BUILDER)
            ) {
                $both[] = $relative;
            }
        }

        $message = \sprintf(
            'These files import both query builders, so the DBAL exemption above would cover their ORM '
            . "calls too. Split them:\n%s",
            \implode("\n", $both),
        );

        $this->assertSame([], $both, $message);
    }

    /**
     * Without this, every assertion above is satisfied by a sweep that reached nothing.
     */
    #[Test]
    public function theSweepStillReachesTheCallSitesItJudges(): void
    {
        $files = 0;
        $callsPerRoot = ['src' => 0, 'tests' => 0];

        foreach ($this->sweep() as $relative => $source) {
            ++$files;
            $root = \str_starts_with($relative, 'src/') ? 'src' : 'tests';
            $callsPerRoot[$root] += \count(OrderByArguments::callsIn($source));
        }

        $total = \array_sum($callsPerRoot);

        $this->assertGreaterThanOrEqual(self::MINIMUM_FILES_SWEPT, $files, 'the file sweep collapsed');
        $this->assertGreaterThanOrEqual(self::MINIMUM_CALL_SITES, $total, 'no order-by call sites were parsed');
        $this->assertGreaterThan(0, $callsPerRoot['src'], 'no order-by call site was parsed under api/src');
        $this->assertGreaterThan(0, $callsPerRoot['tests'], 'no order-by call site was parsed under api/tests');
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    private function sweep(): iterable
    {
        $base = \dirname(__DIR__, 3);

        foreach (['src', 'tests'] as $directory) {
            $root = $base . '/' . $directory;

            if (!\is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $source = \file_get_contents($file->getPathname());

                if (false === $source) {
                    continue;
                }

                yield \str_replace($base . '/', '', $file->getPathname()) => $source;
            }
        }
    }

    private function imports(string $source, string $class): bool
    {
        return \array_any(ClassImports::of($source), static fn (array $import): bool => $import['name'] === $class);
    }
}
