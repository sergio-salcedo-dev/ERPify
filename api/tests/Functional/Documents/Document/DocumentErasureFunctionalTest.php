<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentCustodyActorPersonReferences;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentUploaderPersonReferences;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentCustodyRepository;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Privacy\Application\PersonReferenceErasure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The person's erasure as it reaches the Documents tables, against real Postgres: both statements, the state
 * they ignore, the hash they null, what they leave alone, and the detective source that proves the result.
 *
 * Every assertion reads through DBAL. A managed instance would answer from the identity map, and the erasure is
 * a bulk statement that no managed instance sees — the very limit the ADR records — so an ORM read here could
 * report the subject's id long after the row stopped holding it, or fail to.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(PurgeUserDocumentReferences::class)]
#[CoversClass(DoctrineDocumentRepository::class)]
#[CoversClass(DoctrineDocumentCustodyRepository::class)]
#[CoversClass(DbalDocumentUploaderPersonReferences::class)]
#[CoversClass(DbalDocumentCustodyActorPersonReferences::class)]
final class DocumentErasureFunctionalTest extends KernelTestCase
{
    use PlantsDocumentRows;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();

        parent::tearDown();
    }

    #[Test]
    public function theSeamTheIdentityErasureCallsResolvesToTheDocumentsOwner(): void
    {
        $this->assertInstanceOf(PurgeUserDocumentReferences::class, $this->service(PersonReferenceErasure::class));
    }

    #[Test]
    public function noTableHoldsTheSubjectAfterThePurgeInEveryDocumentStateAndTheHashIsNull(): void
    {
        $subject = Uuid::generate();
        $other = Uuid::generate();
        $hash = \hash('sha256', 'subject key');
        $documents = [];

        foreach (DocumentState::cases() as $index => $state) {
            $documents[$state->value] = $this->plantDocument($subject, $state, \hash('sha256', 'key ' . $index));
        }

        // A custody row the subject authored on somebody else's document, and the other person's own rows.
        $theirs = $this->plantDocument($other, DocumentState::Ready, $hash);
        $this->plantCustody($theirs, 1, $other);
        $this->plantCustody($theirs, 2, $subject);
        $this->plantCustody($documents[DocumentState::Erased->value], 1, $subject);
        $this->plantCustody($documents[DocumentState::Received->value], 1, $subject);
        $before = $this->documentRow($theirs);

        $rewritten = $this->purge($subject);

        // 6 documents + 3 custody rows: asserted before the absences, so a purge that matched nothing cannot
        // pass as the subject being gone.
        $this->assertSame(9, $rewritten);

        foreach ($documents as $state => $id) {
            $row = $this->documentRow($id);
            $this->assertSame(ErasedPersonMarker::UUID, $row['uploader_id'], \sprintf('%s: uploader', $state));
            $this->assertNull($row['idempotency_key_hash'], \sprintf('%s: idempotency hash', $state));
            $this->assertSame($state, $row['state'], \sprintf('%s: an erasure never changes the state', $state));
        }

        $this->assertSame([ErasedPersonMarker::UUID], $this->actorsOf($documents[DocumentState::Erased->value]));
        $this->assertSame(
            [$other, ErasedPersonMarker::UUID],
            $this->actorsOf($theirs),
            'the subject becomes the marker on a document someone else uploaded; the other person is untouched',
        );
        $this->assertSame(
            $before,
            $this->documentRow($theirs),
            "another person's document, including its hash, is spared",
        );
        $this->assertSame([], $this->tablesHolding($subject));
    }

    #[Test]
    public function aDocumentIsNeverErasedByItsUploadersErasure(): void
    {
        $subject = Uuid::generate();
        $id = $this->plantDocument($subject, DocumentState::Ready);
        $digest = $this->documentRow($id)['digest'];

        $this->purge($subject);

        $this->assertSame(1, $this->rowsIn('document'), 'the row stays');
        $this->assertSame(DocumentState::Ready->value, $this->documentRow($id)['state']);
        $this->assertSame($digest, $this->documentRow($id)['digest'], 'the digest survives');
    }

    #[Test]
    public function aSecondPassFindsNothingAndTheSubjectIsMatchedWhateverTheCasingOfTheId(): void
    {
        $subject = Uuid::generate();
        $this->plantDocument($subject);

        $this->assertSame(
            1,
            $this->purge(\strtoupper($subject)),
            'RFC 4122 hex is case-insensitive: one id, two spellings',
        );
        $this->assertSame(0, $this->purge($subject));
    }

    #[Test]
    public function aStatementOutsideTheCallersTransactionIsRefusedBecauseItsLocksWouldBeReleasedAtOnce(): void
    {
        $subject = Uuid::generate();

        $cases = [
            fn () => $this->service(DocumentRepository::class)->anonymiseUploader($subject),
            fn () => $this->service(DocumentCustodyRepository::class)->anonymiseActor($subject),
        ];

        foreach ($cases as $statement) {
            try {
                $statement();
                $this->fail('Expected a refusal.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function theDetectiveSourcesListTheSubjectBeforeThePurgeAndNothingAfterIt(): void
    {
        $subject = Uuid::generate();
        $other = Uuid::generate();
        $document = $this->plantDocument($subject);
        $this->plantCustody($document, 1, $subject);
        $this->plantCustody($this->plantDocument($other), 1, $other);

        $this->assertEqualsCanonicalizing([$subject, $other], $this->uploaders());
        $this->assertEqualsCanonicalizing([$subject, $other], $this->actors());

        $this->purge($subject);

        $this->assertSame([$other], $this->uploaders());
        $this->assertSame([$other], $this->actors());
    }

    #[Test]
    public function aRowThatAlreadyNamesTheMarkerIsReportedByNeitherSource(): void
    {
        $document = $this->plantDocument(ErasedPersonMarker::UUID);
        $this->plantCustody($document, 1, ErasedPersonMarker::UUID);

        $this->assertSame([], $this->uploaders());
        $this->assertSame([], $this->actors());
    }

    #[Test]
    public function theSourcesDeclareTheColumnsTheRegistryClassifies(): void
    {
        $connection = $this->connection();

        $this->assertSame(
            \Erpify\Documents\Document\Domain\Entity\Document::class . '::$uploaderId',
            (new DbalDocumentUploaderPersonReferences($connection))->axis()->key(),
        );
        $this->assertSame(
            \Erpify\Documents\Document\Domain\Entity\DocumentCustody::class . '::$actorId',
            (new DbalDocumentCustodyActorPersonReferences($connection))->axis()->key(),
        );
    }

    private function purge(string $subject): int
    {
        return $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PersonReferenceErasure::class)->eraseReferencesTo($subject),
        );
    }

    /**
     * @return list<string>
     */
    private function uploaders(): array
    {
        return (new DbalDocumentUploaderPersonReferences($this->connection()))->retainedPersonIds();
    }

    /**
     * @return list<string>
     */
    private function actors(): array
    {
        return (new DbalDocumentCustodyActorPersonReferences($this->connection()))->retainedPersonIds();
    }

    /**
     * Every table and column of the public schema that could hold an identifier, searched for the subject's id as
     * text. Not a list of tables somebody remembered: the schema is the universe.
     *
     * @return list<string> `table.column` pairs holding the id, empty when no table does
     */
    private function tablesHolding(string $subject): array
    {
        $connection = $this->connection();
        $found = [];

        foreach ($this->identifierColumns($connection) as [$table, $column]) {
            $count = $this->intOf($connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM "%s" WHERE CAST("%s" AS TEXT) ILIKE :needle', $table, $column),
                ['needle' => '%' . $subject . '%'],
            ));

            if ($count > 0) {
                $found[] = $table . '.' . $column;
            }
        }

        return $found;
    }

    /**
     * @return list<array{string, string}>
     */
    private function identifierColumns(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT c.table_name, c.column_name FROM information_schema.columns c '
            . 'JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name '
            . "WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' "
            . "AND c.data_type IN ('uuid', 'character varying', 'text', 'json', 'jsonb') "
            . 'ORDER BY c.table_name, c.column_name',
        );

        $this->assertGreaterThan(50, \count($rows), 'the sweep must cover the schema or it proves nothing');

        return \array_map(
            fn (array $row): array => [
                $this->stringOf($row['table_name'] ?? null),
                $this->stringOf($row['column_name'] ?? null),
            ],
            $rows,
        );
    }
}
