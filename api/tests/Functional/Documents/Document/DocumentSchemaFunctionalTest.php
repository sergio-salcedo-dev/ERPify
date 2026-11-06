<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DocumentCustodyDocumentForeignKeySchemaListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * What the schema and the mapping promise, asserted against the database the suite runs on.
 *
 * DBAL does not introspect CHECK constraints, so `doctrine:schema:validate` is silent about all of them and
 * the migration owns them; each is proven here by trying to break it. The foreign key is proven twice, as a
 * constraint (it exists and has no cascade) and as behaviour (a document with custody rows cannot be deleted),
 * and the mapping is read for the two ORM-side halves of the same promise: no association to cascade through,
 * and an entity that can never emit an `UPDATE`.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one case per constraint the migration owns, since DBAL introspects
 *                                                 none of its CHECKs
 */
#[CoversClass(DocumentCustodyDocumentForeignKeySchemaListener::class)]
#[CoversClass(Document::class)]
#[CoversClass(DocumentCustody::class)]
final class DocumentSchemaFunctionalTest extends KernelTestCase
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
    public function theCustodyForeignKeyExistsAndHasNeitherAnUpdateNorADeleteCascade(): void
    {
        $constraint = $this->connection()->fetchAssociative(
            'SELECT confdeltype, confupdtype, confrelid::regclass::text AS referenced FROM pg_constraint '
            . "WHERE conname = 'fk_document_custody_document'",
        );

        $this->assertIsArray($constraint, 'the foreign key is physically present');
        $this->assertSame('document', $constraint['referenced'] ?? null);
        $this->assertSame('a', $constraint['confdeltype'] ?? null, 'ON DELETE NO ACTION: custody outlives deletes');
        $this->assertSame('a', $constraint['confupdtype'] ?? null, 'ON UPDATE NO ACTION');
    }

    #[Test]
    public function aDocumentThatHasCustodyCannotBeDeleted(): void
    {
        $documentId = $this->plantDocument(Uuid::generate());
        $this->plantCustody($documentId, 1, Uuid::generate());

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection()->executeStatement(
            'DELETE FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        );
    }

    #[Test]
    public function aCustodyRowCannotNameADocumentThatDoesNotExist(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->plantCustody(Uuid::generate(), 1, Uuid::generate());
    }

    #[Test]
    public function theMappingHasNoAssociationSoNothingCanCascadeOrOrphanRemoveThroughTheOrm(): void
    {
        $entityManager = $this->service(EntityManagerInterface::class);

        foreach ([Document::class, DocumentCustody::class] as $entity) {
            $metadata = $entityManager->getClassMetadata($entity);

            $this->assertSame([], $metadata->getAssociationNames(), \sprintf('%s maps no association', $entity));
        }
    }

    #[Test]
    public function theCustodyEntityIsReadOnlyAndTheImmutableDocumentColumnsAreNeverUpdated(): void
    {
        $entityManager = $this->service(EntityManagerInterface::class);

        $custody = $entityManager->getClassMetadata(DocumentCustody::class);
        $this->assertTrue($custody->isReadOnly, 'the ORM emits no UPDATE for a custody row');

        $document = $entityManager->getClassMetadata(Document::class);

        foreach (['seriesId', 'predecessorId', 'scopeId', 'digest', 'sizeBytes'] as $field) {
            $mapping = $document->fieldMappings[$field] ?? null;
            $this->assertTrue($mapping->notUpdatable ?? false, \sprintf('%s has no UPDATE path', $field));
        }
    }

    #[Test]
    public function theStateColumnRefusesAValueOutsideTheLifecycle(): void
    {
        $id = $this->plantDocument(Uuid::generate());

        $this->assertCheckViolation(fn (): int|string => $this->connection()->executeStatement(
            'UPDATE document SET state = :state WHERE id = CAST(:id AS UUID)',
            ['state' => 'archived', 'id' => $id],
        ));
    }

    #[Test]
    public function everyDocumentStateTheLifecycleNamesIsAccepted(): void
    {
        foreach (DocumentState::cases() as $state) {
            $id = $this->plantDocument(Uuid::generate(), $state);

            $this->assertSame($state->value, $this->documentRow($id)['state']);
        }
    }

    #[Test]
    public function theContentColumnsRefuseWhatCouldNeverBeAnOriginal(): void
    {
        $id = $this->plantDocument(Uuid::generate());

        $cases = [
            'digest' => "digest = 'ABC'",
            'size' => 'size_bytes = 0',
            'idempotency key hash' => "idempotency_key_hash = 'nope'",
        ];

        foreach ($cases as $label => $assignment) {
            $this->assertCheckViolation(
                fn (): int|string => $this->connection()->executeStatement(
                    \sprintf('UPDATE document SET %s WHERE id = CAST(:id AS UUID)', $assignment),
                    ['id' => $id],
                ),
                \sprintf('The %s CHECK let a bad value through.', $label),
            );
        }
    }

    #[Test]
    public function aCustodySequenceStartsAtOne(): void
    {
        $documentId = $this->plantDocument(Uuid::generate());

        $this->assertCheckViolation(fn (): string => $this->plantCustody($documentId, 0, Uuid::generate()));
    }

    #[Test]
    public function theCustodyEventColumnCarriesNoCheckSoALaterStoryAddsACaseWithoutAMigration(): void
    {
        $documentId = $this->plantDocument(Uuid::generate());

        $this->connection()->executeStatement(
            'INSERT INTO document_custody (id, document_id, seq, event, actor_id, created_at) '
            . "VALUES (:id, :document, 1, 'a_fact_a_later_story_writes', :actor, NOW())",
            ['id' => Uuid::generate(), 'document' => $documentId, 'actor' => Uuid::generate()],
        );

        $this->assertSame(1, $this->rowsIn('document_custody'));
    }

    #[Test]
    public function twoDocumentsCannotShareAnEncryptionScope(): void
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $insert = 'INSERT INTO document '
            . '(id, scope_id, series_id, uploader_id, state, digest, size_bytes, next_scan_at, created_at, updated_at) '
            . "VALUES (:id, :scope, :id, :uploader, 'received', :digest, 1, NOW(), NOW(), NOW())";
        $this->connection()->executeStatement($insert, [
            'id' => Uuid::generate(),
            'scope' => $scope,
            'uploader' => Uuid::generate(),
            'digest' => \hash('sha256', 'a'),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection()->executeStatement($insert, [
            'id' => Uuid::generate(),
            'scope' => $scope,
            'uploader' => Uuid::generate(),
            'digest' => \hash('sha256', 'b'),
        ]);
    }

    /**
     * DBAL maps no exception class to SQLSTATE `23514`, so a check violation is recognised by the state.
     *
     * @param Closure(): mixed $statement
     */
    private function assertCheckViolation(
        Closure $statement,
        string $message = 'A CHECK constraint was expected to refuse the write.',
    ): void {
        try {
            $statement();
        } catch (DriverException $driverException) {
            $this->assertSame('23514', $driverException->getSQLState(), $message);

            return;
        }

        $this->fail($message);
    }
}
