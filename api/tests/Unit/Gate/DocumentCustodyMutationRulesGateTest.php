<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\DocumentCustodyMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies {@see DocumentCustodyMutations} against synthetic source, one fixture per supported write surface,
 * so {@see DocumentCustodyMutationGateTest} can trust it over the real tree without re-deriving it.
 *
 * Per docs/rules/testing.md ("assert the seed before asserting the absence"): every shape that must NOT count
 * sits in a fixture that also carries one that must, and the expectation is that one exactly. A detector that
 * finds nothing and a detector that cannot find anything report the same empty list; here they cannot, because
 * the empty list is never the expected answer.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") one fixture per write surface, which is the point of the class
 */
#[CoversNothing]
final class DocumentCustodyMutationRulesGateTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideEveryWriteSurfaceIsReportedCases')]
    public function everyWriteSurfaceIsReported(string $code, array $expected): void
    {
        $this->assertSame($expected, DocumentCustodyMutations::in("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideEveryWriteSurfaceIsReportedCases(): iterable
    {
        yield from self::sqlSurfaceCases();
        yield from self::dbalTableApiCases();
        yield from self::ormSurfaceCases();
        yield from self::removeCases();
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideWhatIsNotAMutationOfTheLogIsNotReportedCases')]
    public function whatIsNotAMutationOfTheLogIsNotReported(string $code, array $expected): void
    {
        $this->assertSame($expected, DocumentCustodyMutations::in("<?php\n" . $code));
    }

    /**
     * Each fixture carries one real mutation beside the shape that must stay quiet.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideWhatIsNotAMutationOfTheLogIsNotReportedCases(): iterable
    {
        yield 'an insert is the append' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO document_custody (id) VALUES (:id)');
                $c->executeStatement('DELETE FROM document_custody WHERE id = :id');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'an insert that does nothing on conflict is still an append' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO document_custody (id) VALUES (:id) ON CONFLICT (id) DO NOTHING');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'a read, and the row lock of a read' => [
            <<<'PHP'
                $c->fetchOne('SELECT MAX(seq) FROM document_custody WHERE document_id = :id');
                $c->fetchAllAssociative('SELECT id FROM document_custody ORDER BY id FOR UPDATE');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'a comment or a docblock quoting a statement' => [
            <<<'PHP'
                // UPDATE document_custody SET actor_id = :a
                /** DELETE FROM document_custody */
                $c->executeStatement('TRUNCATE document_custody');
                PHP,
            ['TRUNCATE document_custody'],
        ];

        yield 'the snapshot table and a neighbour whose name starts the same' => [
            <<<'PHP'
                $c->executeStatement('UPDATE document SET state = :s');
                $c->executeStatement('DELETE FROM document_custody_archive');
                $c->executeStatement('UPDATE other_document_custody SET x = 1');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'the other logs, which have a gate of their own' => [
            <<<'PHP'
                $c->executeStatement('UPDATE audit_log SET ip = :ip');
                $c->executeStatement('DELETE FROM event_store');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'an entity whose name ends the same' => [
            <<<'PHP'
                $em->createQuery('UPDATE ' . OtherDocumentCustody::class . ' c SET c.x = 1');
                $qb->update(NotDocumentCustodyAtAll::class, 'c');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield from self::quietEntityAndTableCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function quietEntityAndTableCases(): iterable
    {
        yield 'a persist of the entity, and a remove in a file that does not name it' => [
            <<<'PHP'
                $em->persist(new Other());
                $em->remove($somethingElse);
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'a read from the repository of the entity, and a select from it' => [
            <<<'PHP'
                $em->getRepository(DocumentCustody::class)->createQueryBuilder('c')->select('c')
                    ->getQuery()->getResult();
                $em->createQueryBuilder()->from(DocumentCustody::class, 'c')->select('c')->where('c.seq = 1');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'a read through the entity class' => [
            <<<'PHP'
                $em->getRepository(DocumentCustody::class)->findBy(['documentId' => $id]);
                $em->createQuery('SELECT c FROM ' . DocumentCustody::class . ' c');
                $qb->select('c')->from(DocumentCustody::class, 'c');
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'ON DELETE CASCADE in a statement about another table' => [
            <<<'PHP'
                $c->executeStatement('ALTER TABLE other ADD FOREIGN KEY (a) REFERENCES document_custody (id)'
                    . ' ON DELETE CASCADE');
                $c->executeStatement('DELETE FROM document_custody');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a truncate of the snapshot table without a cascade' => [
            <<<'PHP'
                $c->executeStatement('TRUNCATE document');
                $c->executeStatement('TRUNCATE document_custody');
                PHP,
            ['TRUNCATE document_custody'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function sqlSurfaceCases(): iterable
    {
        yield 'a plain update, and a lower-case one' => [
            <<<'PHP'
                $c->executeStatement('UPDATE document_custody SET actor_id = :a');
                $c->executeStatement("update document_custody set actor_id = :a");
                PHP,
            ['UPDATE document_custody', 'UPDATE document_custody'],
        ];

        yield 'a delete' => [
            <<<'PHP'
                $c->executeStatement('DELETE FROM document_custody WHERE id = :id');
                PHP,
            ['DELETE document_custody'],
        ];

        yield 'a merge' => [
            <<<'PHP'
                $c->executeStatement('MERGE INTO document_custody t USING s ON t.id = s.id WHEN MATCHED THEN DELETE');
                PHP,
            ['MERGE document_custody'],
        ];

        yield 'a truncate, alone and in a list' => [
            <<<'PHP'
                $c->executeStatement('TRUNCATE document_custody');
                $c->executeStatement('TRUNCATE TABLE document, document_custody RESTART IDENTITY');
                PHP,
            ['TRUNCATE document_custody', 'TRUNCATE document_custody'],
        ];

        yield 'a truncate of the parent table that cascades into the log' => [
            <<<'PHP'
                $c->executeStatement('TRUNCATE document CASCADE');
                PHP,
            ['TRUNCATE document_custody'],
        ];

        yield 'an upsert that rewrites a row while reading as an append' => [
            <<<'PHP'
                $c->executeStatement('INSERT INTO document_custody (id, actor_id) VALUES (:i, :a)'
                    . ' ON CONFLICT (id) DO UPDATE SET actor_id = :a');
                PHP,
            ['UPSERT document_custody'],
        ];

        yield from self::commentAndAssemblyCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function commentAndAssemblyCases(): iterable
    {
        yield 'a comment between the verb and the table' => [
            <<<'PHP'
                $c->executeStatement('UPDATE /* why */ document_custody SET actor_id = :a');
                $c->executeStatement("DELETE FROM -- why\n document_custody");
                $c->executeStatement('UPDATE document_custody /* trailing */ SET actor_id = :a');
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody', 'UPDATE document_custody'],
        ];

        yield 'a verb and a table in different literals' => [
            <<<'PHP'
                $c->executeStatement('UPDATE ' . 'document_custody' . ' SET actor_id = :a');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'a table held in a local constant' => [
            <<<'PHP'
                final class Writer {
                    private const string TABLE = 'document_custody';
                    public function f(): void {
                        $this->c->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE id = :id');
                    }
                }
                PHP,
            ['DELETE document_custody'],
        ];

        yield from self::formattingCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function formattingCases(): iterable
    {
        yield 'a statement in a heredoc' => [
            <<<'PHP'
                $c->executeStatement(<<<SQL
                    UPDATE document_custody
                    SET actor_id = :a
                    SQL);
                PHP,
            ['UPDATE document_custody'],
        ];

        yield from self::spellingCases();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function spellingCases(): iterable
    {
        yield 'a schema-qualified and a quoted table' => [
            <<<'PHP'
                $c->executeStatement('UPDATE public.document_custody SET actor_id = :a');
                $c->executeStatement('DELETE FROM "document_custody"');
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];

        yield 'the statement the erasure sanctions, as it is written' => [
            <<<'PHP'
                $c->executeStatement(
                    'UPDATE document_custody '
                    . 'SET actor_id = CAST(:marker AS UUID) '
                    . 'WHERE id IN ('
                    . 'SELECT id FROM document_custody WHERE actor_id = CAST(:subject_id AS UUID) '
                    . 'ORDER BY id FOR UPDATE'
                    . ')',
                );
                PHP,
            ['UPDATE document_custody'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function dbalTableApiCases(): iterable
    {
        yield 'the table-level update and delete' => [
            <<<'PHP'
                $c->update('document_custody', ['actor_id' => $a], ['id' => $id]);
                $c->delete('document_custody', ['id' => $id]);
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];

        yield 'the query builder of DBAL, with an alias' => [
            <<<'PHP'
                $c->createQueryBuilder()->update('document_custody', 'c')->set('actor_id', ':a');
                $c->createQueryBuilder()->delete('document_custody c');
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];

        yield 'a named table argument in any position' => [
            <<<'PHP'
                $c->update(data: ['a' => 1], table: 'document_custody', criteria: []);
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'the insert method is the append and the same call on another table is not ours' => [
            <<<'PHP'
                $c->insert('document_custody', ['id' => $id]);
                $c->update('document', ['state' => 's'], ['id' => $id]);
                $c->delete('document_custody', ['id' => $id]);
                PHP,
            ['DELETE document_custody'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function ormSurfaceCases(): iterable
    {
        yield 'dql built around the class constant' => [
            <<<'PHP'
                $em->createQuery('UPDATE ' . DocumentCustody::class . ' c SET c.actorId = :a')->execute();
                $em->createQuery('DELETE FROM ' . DocumentCustody::class . ' c')->execute();
                $em->createQuery('DELETE ' . DocumentCustody::class . ' c')->execute();
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody', 'DELETE document_custody'],
        ];

        yield 'the query builder with the class constant' => [
            <<<'PHP'
                $em->createQueryBuilder()->update(DocumentCustody::class, 'c')->set('c.actorId', ':a');
                $em->createQueryBuilder()->delete(DocumentCustody::class, 'c');
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];

        yield 'the query builder opened bare and pointed at the entity by from()' => [
            <<<'PHP'
                $em->createQueryBuilder()->delete()->from(DocumentCustody::class, 'c')->getQuery()->execute();
                $em->createQueryBuilder()->update()->set('c.actorId', ':a')->from(DocumentCustody::class, 'c');
                PHP,
            ['DELETE document_custody', 'UPDATE document_custody'],
        ];

        yield 'the repository of the entity, then the query builder, then the verb' => [
            <<<'PHP'
                $em->getRepository(DocumentCustody::class)->createQueryBuilder('c')->update()->set('c.actorId', ':a');
                $em->getRepository(DocumentCustody::class)->createQueryBuilder('c')->delete()->getQuery()->execute();
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];

        yield 'the verb after from()' => [
            <<<'PHP'
                $em->createQueryBuilder()->from(DocumentCustody::class, 'c')->delete()->getQuery()->execute();
                $em->createQueryBuilder()->from(DocumentCustody::class, 'c')->update()->set('c.actorId', ':a');
                PHP,
            ['DELETE document_custody', 'UPDATE document_custody'],
        ];

        yield 'a fully qualified class constant' => [
            <<<'PHP'
                $em->createQueryBuilder()->update(\Erpify\Documents\Document\Domain\Entity\DocumentCustody::class, 'c');
                PHP,
            ['UPDATE document_custody'],
        ];

        yield 'dql spelling the class name in the string' => [
            <<<'PHP'
                $em->createQuery('UPDATE Erpify\Documents\Document\Domain\Entity\DocumentCustody c SET c.actorId = :a');
                $em->createQuery('DELETE FROM DocumentCustody c');
                PHP,
            ['UPDATE document_custody', 'DELETE document_custody'],
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    private static function removeCases(): iterable
    {
        yield 'remove() in a file that names the entity, on any receiver' => [
            <<<'PHP'
                use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
                final class Remover {
                    public function f(DocumentCustody $row): void {
                        $this->entityManager->remove($row);
                        $this?->other->remove($x);
                    }
                }
                PHP,
            ['REMOVE document_custody', 'REMOVE document_custody'],
        ];
    }
}
