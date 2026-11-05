<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;
use UnexpectedValueException;

/**
 * The custody log by COMPOSITION: the ORM inserts a row once (the entity is `readOnly`, so it can never emit an
 * `UPDATE`), and DBAL reads the sequence and runs the single sanctioned rewrite. There is no method here that
 * removes or updates a row, and `make php.lint.document-custody` holds the set of statements that touch the
 * table to exactly the actor anonymisation below.
 *
 * `append()` flushes inside the caller's transaction and maps ONE violation: `UNIQUE (document_id, seq)`, by
 * its constraint name. Any other unique violation, and every other failure, is rethrown unchanged.
 */
#[AsAlias(DocumentCustodyRepository::class)]
final readonly class DoctrineDocumentCustodyRepository implements DocumentCustodyRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    #[Override]
    public function nextSequenceFor(Document $locked): int
    {
        $highest = $this->connection->fetchOne(
            'SELECT COALESCE(MAX(seq), 0) FROM document_custody WHERE document_id = CAST(:document_id AS UUID)',
            ['document_id' => $locked->getId()],
        );

        // `COALESCE` makes a row certain, so anything but a number is the store handing back a shape that cannot
        // be right — and a default here would mint a sequence number out of nothing.
        if (!\is_numeric($highest)) {
            throw new UnexpectedValueException('The highest custody sequence number was not a number.');
        }

        return (int) $highest + 1;
    }

    #[Override]
    public function append(DocumentCustody $custody): void
    {
        try {
            $this->entityManager->persist($custody);
            $this->entityManager->flush();
        } catch (Throwable $throwable) {
            if (ViolatedUniqueConstraint::is($throwable, DocumentCustody::SEQUENCE_CONSTRAINT)) {
                throw CustodySequenceCollision::create();
            }

            throw $throwable;
        }
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        Uuid::ensure($subjectId);
        RequiresActiveTransaction::ensure($this->connection, 'Anonymising the actor of a custody row');

        return (int) $this->connection->executeStatement(
            'UPDATE document_custody '
            . 'SET actor_id = CAST(:marker AS UUID) '
            . 'WHERE id IN ('
            . 'SELECT id FROM document_custody WHERE actor_id = CAST(:subject_id AS UUID) ORDER BY id FOR UPDATE'
            . ')',
            [
                'marker' => ErasedPersonMarker::UUID,
                'subject_id' => $subjectId,
            ],
        );
    }
}
