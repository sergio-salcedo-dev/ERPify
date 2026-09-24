<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine blind spots {@see SanctionedLogMutationGateTest} declares in its header, the counterpart of
 * {@see SanctionedLogMutationRulesGateTest}: that one proves what the engine sees, this one what it does not.
 * A red here means the engine learned to see a shape and the header now under-promises — update both.
 *
 * @internal
 */
#[CoversNothing]
final class SanctionedLogMutationBlindSpotGateTest extends TestCase
{
    /**
     * Each fixture carries one real mutation beside the blind shape, so the expected list is never empty.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideADeclaredBlindSpotStaysBlindCases')]
    public function aDeclaredBlindSpotStaysBlind(string $code, array $expected): void
    {
        $this->assertSame($expected, SanctionedLogMutations::in("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideADeclaredBlindSpotStaysBlindCases(): iterable
    {
        yield 'a statement assembled by sprintf' => [
            <<<'PHP'
                $c->executeStatement(sprintf('UPDATE %s SET x = 1', 'audit_log'));
                $c->executeStatement('UPDATE event_store SET x = 1');
                PHP,
            ['UPDATE event_store'],
        ];

        yield 'an interpolation before the table' => [
            <<<'PHP'
                $c->executeStatement("DELETE FROM {$schema}.event_store");
                $c->executeStatement('DELETE FROM audit_log');
                PHP,
            ['DELETE audit_log'],
        ];

        yield "another class's constant" => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . Other::TABLE . ' SET x = 1');
                $c->executeStatement('TRUNCATE event_store');
                PHP,
            ['TRUNCATE event_store'],
        ];

        yield from self::assemblyBlindSpotCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function assemblyBlindSpotCases(): iterable
    {
        yield 'a statement finished with .=' => [
            <<<'PHP'
                $sql = 'UPDATE ';
                $sql .= 'audit_log SET x = 1';
                $c->executeStatement('DELETE FROM event_store');
                PHP,
            ['DELETE event_store'],
        ];

        yield 'a table held in a variable' => [
            <<<'PHP'
                $table = 'audit_log';
                $c->executeStatement('UPDATE ' . $table . ' SET x = 1');
                $c->executeStatement('DELETE FROM event_store');
                PHP,
            ['DELETE event_store'],
        ];

        yield 'DDL, which is out of the set' => [
            <<<'PHP'
                $c->executeStatement('DROP TABLE audit_log');
                $c->executeStatement('ALTER TABLE event_store ADD COLUMN x INT');
                $c->executeStatement('UPDATE event_store SET x = 1');
                PHP,
            ['UPDATE event_store'],
        ];

        yield 'a parenthesised sub-expression' => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . ('audit_log') . ' SET x = 1');
                $c->executeStatement('DELETE FROM event_store');
                PHP,
            ['DELETE event_store'],
        ];

        yield 'a constant named by its own class rather than self::' => [
            <<<'PHP'
                final class Writer {
                    private const string TABLE = 'event_store';
                    public function f(): void {
                        $this->c->executeStatement('UPDATE ' . Writer::TABLE . ' SET x = 1');
                        $this->c->executeStatement('UPDATE audit_log SET x = 1');
                    }
                }
                PHP,
            ['UPDATE audit_log'],
        ];

        yield 'a ; inside a quoted value cuts an upsert short' => [
            <<<'PHP'
                $c->executeStatement("INSERT INTO audit_log (a) VALUES (';') ON CONFLICT (id) DO UPDATE SET a = 1");
                $c->executeStatement('DELETE FROM audit_log');
                PHP,
            ['DELETE audit_log'],
        ];

        yield 'a statement held whole in a constant counts twice, the declared false red' => [
            <<<'PHP'
                final class Writer {
                    private const string STATEMENT = 'UPDATE audit_log SET x = 1';
                    public function f(): void { $this->c->executeStatement(self::STATEMENT); }
                }
                PHP,
            ['UPDATE audit_log', 'UPDATE audit_log'],
        ];
    }
}
