<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpStringExpressions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the clause the two Documents erasure statements claim is load-bearing: the inner
 * `SELECT id … ORDER BY id FOR UPDATE` that takes the subject's rows in ascending id BEFORE the outer `UPDATE`
 * rewrites any of them.
 *
 * It matters because the identity erasure and its re-sweep run for the SAME subject and so touch the same
 * rows, and a bare `UPDATE … WHERE actor_id = :subject` takes them in whatever order the planner chose: two such
 * transactions walking one set in opposite directions is a deadlock, answered as a 503 on an erasure the
 * application has already promised. Nothing behavioural reaches this — the statement returns the same count
 * with or without the clause, and a single process cannot make two transactions race — so it is read from the
 * source, over string literals and `.` concatenations with comments excluded, the way the sanctioned-mutation
 * gates read theirs.
 *
 * **What a green proves:** each of the two statements keeps an inner `SELECT id FROM <its table> WHERE … ORDER BY
 * id FOR UPDATE` as the subquery of its `WHERE id IN (…)`. **What it cannot see:** that Postgres executes the
 * subquery before the outer rewrite (the plan is the planner's), a statement assembled at runtime, or the clause
 * moving to a different statement in the same file.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentErasureStatementLockOrderGateTest extends TestCase
{
    private const string DIRECTORY = 'Documents/Document/Infrastructure/Persistence/Doctrine/';

    #[Test]
    #[DataProvider('provideTheStatementTakesItsRowsInAscendingIdBeforeRewritingAnyCases')]
    public function theStatementTakesItsRowsInAscendingIdBeforeRewritingAny(string $file, string $table): void
    {
        $source = \file_get_contents(ApiSourceFiles::root() . '/' . $file);
        $this->assertIsString($source);

        $statements = $this->rewritesOf($source, $table);

        $this->assertCount(1, $statements, \sprintf('exactly one rewrite of %s is expected in %s', $table, $file));
        $this->assertTrue(
            $this->locksInAscendingId($statements[0], $table),
            \sprintf(
                'The rewrite of %s no longer keeps `WHERE id IN (SELECT id FROM %s WHERE … ORDER BY id FOR UPDATE)`. '
                . 'Without the inner ordered lock, an erasure and its re-sweep of the same subject take the same '
                . "rows in the planner's order and can deadlock.",
                $table,
                $table,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheStatementTakesItsRowsInAscendingIdBeforeRewritingAnyCases(): iterable
    {
        yield 'the uploader rewrite' => [self::DIRECTORY . 'DoctrineDocumentRepository.php', 'document'];
        yield 'the custody actor rewrite' => [
            self::DIRECTORY . 'DoctrineDocumentCustodyRepository.php',
            'document_custody',
        ];
    }

    #[Test]
    public function theReaderRefusesAStatementThatLostAClauseAndIgnoresOneQuotedInAComment(): void
    {
        $intact = "<?php\n\$c->executeStatement('UPDATE document_custody SET a = 1 WHERE id IN ('"
            . " . 'SELECT id FROM document_custody WHERE actor_id = :s ORDER BY id FOR UPDATE' . ')');";
        $noLock = "<?php\n\$c->executeStatement('UPDATE document_custody SET a = 1 WHERE id IN ('"
            . " . 'SELECT id FROM document_custody WHERE actor_id = :s ORDER BY id' . ')');";
        $noOrder = "<?php\n\$c->executeStatement('UPDATE document_custody SET a = 1 WHERE id IN ('"
            . " . 'SELECT id FROM document_custody WHERE actor_id = :s FOR UPDATE' . ')');";
        $bare = "<?php\n\$c->executeStatement('UPDATE document_custody SET a = 1 WHERE actor_id = :s');"
            . "\n// ORDER BY id FOR UPDATE\n";

        $table = 'document_custody';

        $this->assertTrue($this->locksInAscendingId($this->firstRewriteOf($intact, $table), $table));
        $this->assertFalse($this->locksInAscendingId($this->firstRewriteOf($noLock, $table), $table));
        $this->assertFalse($this->locksInAscendingId($this->firstRewriteOf($noOrder, $table), $table));
        $this->assertFalse(
            $this->locksInAscendingId($this->firstRewriteOf($bare, $table), $table),
            'a comment quoting the clause is not the clause',
        );
    }

    private function firstRewriteOf(string $source, string $table): string
    {
        return $this->rewritesOf($source, $table)[0] ?? '';
    }

    /**
     * @return list<string> every string expression that begins an `UPDATE <table>` statement
     */
    private function rewritesOf(string $source, string $table): array
    {
        $expressions = new PhpStringExpressions($source);
        $found = [];
        $count = $expressions->count();

        for ($index = 0; $index < $count;) {
            [$text, $next] = $expressions->at($index);

            if (null === $text) {
                ++$index;

                continue;
            }

            if (1 === \preg_match('/^\s*UPDATE\s+' . \preg_quote($table, '/') . '\s/i', $text)) {
                $found[] = $text;
            }

            $index = $next;
        }

        return $found;
    }

    private function locksInAscendingId(string $statement, string $table): bool
    {
        $pattern = '/\bWHERE\s+id\s+IN\s*\(\s*SELECT\s+id\s+FROM\s+' . \preg_quote($table, '/')
            . '\s+WHERE\s+.+?\bORDER\s+BY\s+id\s+FOR\s+UPDATE\s*\)/is';

        return 1 === \preg_match($pattern, $statement);
    }
}
