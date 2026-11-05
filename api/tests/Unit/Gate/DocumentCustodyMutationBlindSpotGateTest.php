<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\DocumentCustodyMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine blind spots {@see DocumentCustodyMutationGateTest} declares in its header, the counterpart of
 * {@see DocumentCustodyMutationRulesGateTest}: that one proves what the engine sees, this one what it does not.
 * A red here means the engine learned to see a shape and the header now under-promises — update both.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentCustodyMutationBlindSpotGateTest extends TestCase
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
        $this->assertSame($expected, DocumentCustodyMutations::in("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideADeclaredBlindSpotStaysBlindCases(): iterable
    {
        yield 'a statement assembled by sprintf' => [
            <<<'PHP'
                $c->executeStatement(sprintf('UPDATE %s SET x = 1', 'document_custody'));
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'an interpolation before the table' => [
            <<<'PHP'
                $c->executeStatement("DELETE FROM {$schema}.document_custody");
                $c->executeStatement('UPDATE document_custody SET x = 1');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield "another class's constant" => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . Other::TABLE . ' SET x = 1');
                $c->executeStatement('TRUNCATE document_custody');
                PHP,
            ['TRUNCATE document_custody'],
        ];

        yield from self::assemblyCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function assemblyCases(): iterable
    {
        yield 'a table held in a variable' => [
            <<<'PHP'
                $table = 'document_custody';
                $c->executeStatement('UPDATE ' . $table . ' SET x = 1');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a statement finished with .=' => [
            <<<'PHP'
                $sql = 'UPDATE ';
                $sql .= 'document_custody SET x = 1';
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a parenthesised sub-expression' => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . ('document_custody') . ' SET x = 1');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a constant named by its own class rather than self::' => [
            <<<'PHP'
                final class Writer {
                    private const string TABLE = 'document_custody';
                    public function f(): void {
                        $this->c->executeStatement('UPDATE ' . Writer::TABLE . ' SET x = 1');
                        $this->c->executeStatement('DELETE FROM document_custody');
                    }
                }
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a ; inside a quoted value cuts an upsert short' => [
            <<<'PHP'
                $c->executeStatement("INSERT INTO document_custody (a) VALUES (';')"
                    . ' ON CONFLICT (id) DO UPDATE SET a = 1');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield from self::schemaAndEntityCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function schemaAndEntityCases(): iterable
    {
        yield 'DDL, which is schema and belongs to migrations' => [
            <<<'PHP'
                $c->executeStatement('DROP TABLE document_custody');
                $c->executeStatement('ALTER TABLE document_custody ADD COLUMN x INT');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'the entity reached through static::class or an alias, not its own name' => [
            <<<'PHP'
                use Erpify\Documents\Document\Domain\Entity\DocumentCustody as Row;
                $em->createQueryBuilder()->update(Row::class, 'c');
                $em->createQueryBuilder()->update(static::class, 'c');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'the entity named by a string class name held in a variable' => [
            <<<'PHP'
                $entity = 'Erpify\Documents\Document\Domain\Entity\DocumentCustody';
                $em->createQuery('UPDATE ' . $entity . ' c SET c.x = 1');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a remove() in a file that does not name the entity' => [
            <<<'PHP'
                final class Remover {
                    public function f(object $row): void {
                        $this->entityManager->remove($row);
                    }
                }
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield from self::chainCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function chainCases(): iterable
    {
        yield 'the repository of the entity held in a variable before the verb' => [
            <<<'PHP'
                $repository = $em->getRepository(DocumentCustody::class);
                $repository->createQueryBuilder('c')->delete()->getQuery()->execute();
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a verb in a different statement from the one naming the entity' => [
            <<<'PHP'
                $builder = $em->createQueryBuilder()->from(DocumentCustody::class, 'c');
                $builder->delete();
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a comment that splits the table name itself' => [
            <<<'PHP'
                $c->executeStatement('UPDATE document_/* x */custody SET x = 1');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a removal through a wrapper not called remove' => [
            <<<'PHP'
                use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
                $repository->discard($row);
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];
    }
}
