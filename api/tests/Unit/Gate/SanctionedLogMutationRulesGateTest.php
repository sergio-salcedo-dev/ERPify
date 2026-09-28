<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies {@see SanctionedLogMutations} against synthetic source, so {@see SanctionedLogMutationGateTest}
 * can trust it over the real tree without re-deriving it.
 *
 * Per docs/rules/testing.md ("assert the seed before asserting the absence"): every shape that must NOT count
 * is written into a fixture that also carries one that must, and the expectation is that one exactly. A
 * detector that finds nothing and a detector that cannot find anything report the same empty list; here they
 * cannot, because the empty list is never the expected answer.
 *
 * @internal
 */
#[CoversNothing]
final class SanctionedLogMutationRulesGateTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideEveryMutationShapeIsReportedCases')]
    public function everyMutationShapeIsReported(string $code, array $expected): void
    {
        $this->assertSame($expected, SanctionedLogMutations::in("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideEveryMutationShapeIsReportedCases(): iterable
    {
        yield 'a plain update, and a lower-case one' => [
            <<<'PHP'
                $c->executeStatement('UPDATE audit_log SET ip = :ip');
                $c->executeStatement("update event_store set payload = '{}'");
                PHP,
            ['UPDATE audit_log', 'UPDATE event_store'],
        ];

        yield from self::assembledStatementCases();
        yield from self::verbAndTableSpellingCases();
        yield from self::heredocAndTableApiCases();
        yield from self::escapedAndNamedCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function assembledStatementCases(): iterable
    {
        yield 'a verb and a table in different literals' => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . 'event_store' . ' SET aggregate_id = :id');
                PHP,
            ['UPDATE event_store'],
        ];

        yield 'a table held in a local constant' => [
            <<<'PHP'
                final class Pruner {
                    private const string TABLE = 'audit_log';
                    public function prune(): void {
                        $this->c->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE level = :level');
                    }
                }
                PHP,
            ['DELETE audit_log'],
        ];

        yield 'a constant built from another, read through static::' => [
            <<<'PHP'
                class Writer {
                    const PREFIX = 'event', TABLE = self::PREFIX . '_store';
                    public function write(): void {
                        $this->c->executeStatement('UPDATE ' . static::TABLE . ' SET metadata = :m');
                    }
                }
                PHP,
            ['UPDATE event_store'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function verbAndTableSpellingCases(): iterable
    {
        yield 'a schema-qualified quoted identifier' => [
            <<<'PHP'
                $c->executeStatement('UPDATE public."audit_log" SET ip = :ip');
                PHP,
            ['UPDATE audit_log'],
        ];

        yield 'ONLY on every verb that takes it' => [
            <<<'PHP'
                $c->executeStatement('UPDATE ONLY audit_log SET ip = :ip');
                $c->executeStatement('MERGE INTO ONLY event_store e USING s ON TRUE WHEN MATCHED THEN DELETE');
                $c->executeStatement('TRUNCATE ONLY audit_log');
                $c->executeStatement('DELETE FROM ONLY audit_log WHERE id = :id');
                PHP,
            ['UPDATE audit_log', 'MERGE event_store', 'TRUNCATE audit_log', 'DELETE audit_log'],
        ];

        yield 'the log anywhere in a TRUNCATE list, and TRUNCATE TABLE' => [
            <<<'PHP'
                $c->executeStatement('TRUNCATE projection_checkpoint, audit_log RESTART IDENTITY CASCADE');
                $c->executeStatement('TRUNCATE TABLE event_store');
                PHP,
            ['TRUNCATE audit_log', 'TRUNCATE event_store'],
        ];

        yield 'a MERGE' => [
            <<<'PHP'
                $c->executeStatement('MERGE INTO audit_log a USING src s ON a.id = s.id WHEN MATCHED THEN DELETE');
                PHP,
            ['MERGE audit_log'],
        ];

        yield 'an upsert, which reads as an append and rewrites a row' => [
            <<<'PHP'
                $c->executeStatement(
                    'INSERT INTO event_store (event_id) VALUES (:id) '
                    . 'ON CONFLICT (event_id) DO UPDATE SET payload = EXCLUDED.payload'
                );
                PHP,
            ['UPSERT event_store'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function heredocAndTableApiCases(): iterable
    {
        yield 'a nowdoc' => [
            <<<'PHP'
                $c->executeStatement(<<<'SQL'
                    UPDATE audit_log
                    SET user_agent = :redacted
                    SQL);
                PHP,
            ['UPDATE audit_log'],
        ];

        yield 'a heredoc with an interpolation after the table' => [
            <<<'PHP'
                $c->executeStatement(<<<SQL
                    DELETE FROM event_store WHERE id = '{$id}'
                    SQL);
                PHP,
            ['DELETE event_store'],
        ];

        yield "DBAL's table-level update" => [
            <<<'PHP'
                $c->update('audit_log', ['ip' => ''], ['id' => $id]);
                PHP,
            ['UPDATE audit_log'],
        ];

        yield "DBAL's table-level delete, through a nullsafe call and a local constant" => [
            <<<'PHP'
                final class Remover {
                    private const string TABLE = 'event_store';
                    public function remove(): void { $this->c?->delete(self::TABLE, ['id' => 1]); }
                }
                PHP,
            ['DELETE event_store'],
        ];

        yield 'several mutations, in order of appearance' => [
            <<<'PHP'
                $c->executeStatement('DELETE FROM audit_log WHERE id = :id');
                $c->executeStatement('UPDATE event_store SET payload = :p');
                $c->executeStatement('UPDATE audit_log SET ip = :ip');
                PHP,
            ['DELETE audit_log', 'UPDATE event_store', 'UPDATE audit_log'],
        ];

        yield 'several mutations inside one string, in order of appearance' => [
            <<<'PHP'
                $c->executeStatement(
                    'DELETE FROM audit_log WHERE id = :id; TRUNCATE x, event_store; UPDATE audit_log SET ip = :ip',
                );
                PHP,
            ['DELETE audit_log', 'TRUNCATE event_store', 'UPDATE audit_log'],
        ];
    }

    /**
     * Each fixture holds one real mutation beside the shape under test, so the expectation is never the
     * empty list a blind detector would also produce.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideWhatIsNotAMutationIsNotReportedCases')]
    public function whatIsNotAMutationIsNotReported(string $code, array $expected): void
    {
        $this->assertSame($expected, SanctionedLogMutations::in("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideWhatIsNotAMutationIsNotReportedCases(): iterable
    {
        yield 'a statement quoted in comments' => [
            <<<'PHP'
                // UPDATE audit_log SET ip = '' is what the actor pass does.
                /** DELETE FROM event_store would destroy the log. */
                # TRUNCATE audit_log
                $c->executeStatement('UPDATE event_store SET payload = :p');
                PHP,
            ['UPDATE event_store'],
        ];

        yield 'a row lock taken by a read, and one inside an update, which counts once' => [
            <<<'PHP'
                $c->executeQuery('SELECT id FROM audit_log WHERE actor_id = :a ORDER BY id FOR UPDATE');
                $c->executeQuery('SELECT id FROM event_store FOR NO KEY UPDATE SKIP LOCKED');
                $c->executeStatement(
                    'UPDATE audit_log SET ip = :r WHERE id IN ('
                    . 'SELECT id FROM audit_log WHERE actor_id = :a ORDER BY id FOR UPDATE)'
                );
                PHP,
            ['UPDATE audit_log'],
        ];

        yield 'a plain append' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO audit_log (id, action) VALUES (:id, :action)');
                $c->executeStatement('DELETE FROM event_store WHERE id = :id');
                PHP,
            ['DELETE event_store'],
        ];

        yield 'an idempotent append that does nothing on conflict' => [
            <<<'PHP'
                $c->executeStatement(
                    'INSERT INTO event_store (event_id) SELECT :id FROM event_store '
                    . 'ON CONFLICT (event_id) DO NOTHING'
                );
                $c->executeStatement('UPDATE event_store SET payload = :p');
                PHP,
            ['UPDATE event_store'],
        ];

        yield from self::nearMissCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function nearMissCases(): iterable
    {
        yield 'an upsert on the NEXT statement does not taint an append to the log' => [
            <<<'PHP'
                $c->executeStatement(
                    'INSERT INTO audit_log (id) VALUES (:id); '
                    . 'INSERT INTO other (id) VALUES (:id) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id'
                );
                $c->executeStatement('MERGE INTO audit_log a USING s ON TRUE WHEN MATCHED THEN DELETE');
                PHP,
            ['MERGE audit_log'],
        ];

        yield 'a table whose name only starts with the log' => [
            <<<'PHP'
                $c->executeStatement('UPDATE audit_logger SET x = 1');
                $c->executeStatement('DELETE FROM event_store_archive');
                $c->update('audit_log_view', ['x' => 1], ['id' => 1]);
                $c->executeStatement('DELETE FROM audit_log');
                PHP,
            ['DELETE audit_log'],
        ];

        yield 'reads of the log and schema declarations' => [
            <<<'PHP'
                final class Schema {
                    private const string TABLE = 'audit_log';
                    public function read(): void {
                        $this->c->executeQuery('SELECT * FROM ' . self::TABLE);
                        $this->schema->createTable(self::TABLE);
                        $this->qb->select('*')->from(self::TABLE);
                        $this->c->executeStatement('UPDATE ' . self::TABLE . ' SET ip = :ip');
                    }
                }
                PHP,
            ['UPDATE audit_log'],
        ];

        yield 'a table: label nested inside another argument names no table' => [
            <<<'PHP'
                $c->update('users', ['note' => f(table: 'audit_log')], ['id' => 1]);
                $c->delete('audit_log', ['id' => 1]);
                PHP,
            ['DELETE audit_log'],
        ];

        yield 'a table-level update on a different table' => [
            <<<'PHP'
                $c->update('bank', ['name' => $name], ['id' => $id]);
                $c->delete('event_store', ['id' => $id]);
                PHP,
            ['DELETE event_store'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function escapedAndNamedCases(): iterable
    {
        yield 'an interpolated double-quoted string, and escape sequences in one and in a heredoc' => [
            <<<'PHP'
                $c->executeStatement("UPDATE audit_log SET ip = {$ip}");
                $c->executeStatement("UPDATE\taudit_log SET x = 1");
                $c->executeStatement("UPDATE \"event_store\" SET x = {$x}");
                $c->executeStatement(<<<SQL
                    DELETE FROM\taudit_log WHERE id = {$id}
                    SQL);
                PHP,
            ['UPDATE audit_log', 'UPDATE audit_log', 'UPDATE event_store', 'DELETE audit_log'],
        ];

        yield "a named or aliased table argument to DBAL's table-level API" => [
            <<<'PHP'
                $c->update(table: 'audit_log', data: ['ip' => ''], criteria: ['id' => 1]);
                $c->delete(table: 'event_store', criteria: ['id' => 1]);
                $c->update('audit_log a', ['ip' => ''], ['id' => 1]);
                $qb->delete('event_store AS e');
                PHP,
            ['UPDATE audit_log', 'DELETE event_store', 'UPDATE audit_log', 'DELETE event_store'],
        ];

        yield 'a table: named argument after other named arguments' => [
            <<<'PHP'
                $c->update(data: ['ip' => "{$ip}"], table: 'audit_log', criteria: ['id' => 1]);
                $c->delete(criteria: ['id' => f(1, 2)], table: 'event_store');
                PHP,
            ['UPDATE audit_log', 'DELETE event_store'],
        ];

        yield 'a binary-prefixed string, plain and interpolated' => [
            <<<'PHP'
                $c->executeStatement(b"UPDATE\taudit_log SET x = 1");
                $c->executeStatement(B"DELETE FROM event_store WHERE id = {$id}");
                PHP,
            ['UPDATE audit_log', 'DELETE event_store'],
        ];
    }

    /**
     * The comparison the gate asserts, both directions of it — the missing one included, because an extractor
     * that stops seeing anything must read as a vanished member rather than as a clean tree.
     *
     * @param array<string, list<string>> $declared
     * @param array<string, list<string>> $found
     * @param list<string>                $expected
     */
    #[Test]
    #[DataProvider('provideDiscrepanciesAreReportedInBothDirectionsCases')]
    public function discrepanciesAreReportedInBothDirections(array $declared, array $found, array $expected): void
    {
        $this->assertSame($expected, SanctionedLogMutations::discrepancies($declared, $found));
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, array<string, list<string>>, list<string>}>
     */
    public static function provideDiscrepanciesAreReportedInBothDirectionsCases(): iterable
    {
        yield 'a tree matching the declared set, in any order' => [
            ['A.php' => ['UPDATE audit_log'], 'B.php' => ['DELETE audit_log']],
            ['B.php' => ['DELETE audit_log'], 'A.php' => ['UPDATE audit_log']],
            [],
        ];

        yield 'an undeclared mutator, named' => [
            ['A.php' => ['UPDATE audit_log']],
            ['A.php' => ['UPDATE audit_log'], 'Rogue.php' => ['UPDATE event_store']],
            ['Rogue.php issues UPDATE event_store but is not a sanctioned mutator.'],
        ];

        yield 'a sanctioned member that disappeared' => [
            ['A.php' => ['UPDATE audit_log'], 'Pruner.php' => ['DELETE audit_log']],
            ['A.php' => ['UPDATE audit_log']],
            ['Pruner.php is sanctioned for [DELETE audit_log] but no longer issues any mutation the sweep can see.'],
        ];

        yield 'a second mutation in a sanctioned file' => [
            ['A.php' => ['UPDATE audit_log']],
            ['A.php' => ['UPDATE audit_log', 'TRUNCATE audit_log']],
            ['A.php is sanctioned for [UPDATE audit_log] but issues [UPDATE audit_log, TRUNCATE audit_log].'],
        ];
    }
}
