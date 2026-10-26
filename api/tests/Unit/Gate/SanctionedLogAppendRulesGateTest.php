<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies {@see SanctionedLogMutations::appendsIn()} against synthetic source, so
 * {@see ChangeLevelProvenanceGateTest} can trust it over the real tree to find every class appending to
 * `audit_log`. As in {@see SanctionedLogMutationRulesGateTest}, a fixture whose shapes must not count also
 * carries one that must, so an extractor that sees nothing cannot pass as a clean tree.
 *
 * @internal
 */
#[CoversNothing]
final class SanctionedLogAppendRulesGateTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideEveryAppendShapeIsReportedCases')]
    public function everyAppendShapeIsReported(string $code, array $expected): void
    {
        $this->assertSame($expected, SanctionedLogMutations::appendsIn("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideEveryAppendShapeIsReportedCases(): iterable
    {
        yield 'a plain insert, and a lower-case one on the other log' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO audit_log (id) VALUES (:id)');
                $c->executeStatement('insert into event_store (id) values (:id)');
                PHP,
            ['INSERT audit_log', 'INSERT event_store'],
        ];

        yield 'a quoted and a schema-qualified name' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO "audit_log" (id) VALUES (:id)');
                $c->executeStatement('INSERT INTO public.audit_log (id) VALUES (:id)');
                PHP,
            ['INSERT audit_log', 'INSERT audit_log'],
        ];

        yield 'a statement finished by a same-file constant' => [
            <<<'PHP'
                final class W { private const string TABLE = 'audit_log';
                    public function w($c): void {
                        $c->executeStatement('INSERT INTO ' . self::TABLE . ' (id) VALUES (1)');
                    } }
                PHP,
            ['INSERT audit_log'],
        ];

        yield "DBAL's table API, positional and named" => [
            <<<'PHP'
                $c->insert('audit_log', ['id' => 1]);
                $c->insert(data: ['id' => 1], table: 'audit_log');
                PHP,
            ['INSERT audit_log', 'INSERT audit_log'],
        ];

        yield 'an upsert is an append too' => [
            "\$c->executeStatement('INSERT INTO audit_log (id) VALUES (1) ON CONFLICT (id) DO NOTHING');",
            ['INSERT audit_log'],
        ];

        yield 'a comment, a lookalike table and a mutation never count' => [
            <<<'PHP'
                // INSERT INTO audit_log (id) VALUES (1)
                $c->executeStatement('INSERT INTO audit_logger (id) VALUES (1)');
                $c->update('audit_log', ['ip' => null], ['id' => 1]);
                $c->executeStatement('INSERT INTO audit_log (id) VALUES (1)');
                PHP,
            ['INSERT audit_log'],
        ];
    }
}
