<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Rows planted straight through DBAL, so a test controls the state and the person columns without going
 * through the aggregate, and reads the result back the same way.
 *
 * Reads never go through the ORM on purpose: a managed instance would answer from the identity map, and the
 * erasure being tested is a bulk statement that no managed instance sees.
 *
 * The tables are emptied around each test rather than rolled back, because the use cases under test own real
 * transactions. Both are the Documents context's own, so no other suite shares them.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait PlantsDocumentRows
{
    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    private function emptyDocumentTables(): void
    {
        $this->connection()->executeStatement('TRUNCATE document_custody, document');
    }

    private function plantDocument(
        string $uploaderId,
        DocumentState $state = DocumentState::Received,
        ?string $idempotencyKeyHash = null,
    ): string {
        $id = Uuid::generate();

        $this->connection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, predecessor_id, uploader_id, state, digest, size_bytes, '
            . 'idempotency_key_hash, next_scan_at, created_at, updated_at) '
            . 'VALUES (:id, :scope, :id, NULL, :uploader, :state, :digest, 10, :hash, NOW(), NOW(), NOW())',
            [
                'id' => $id,
                'scope' => RandomUuid::v4()->toRfc4122(),
                'uploader' => $uploaderId,
                'state' => $state->value,
                'digest' => \hash('sha256', $id),
                'hash' => $idempotencyKeyHash,
            ],
        );

        return $id;
    }

    private function plantCustody(string $documentId, int $seq, string $actorId): string
    {
        $id = Uuid::generate();

        $this->connection()->executeStatement(
            'INSERT INTO document_custody (id, document_id, seq, event, actor_id, created_at) '
            . 'VALUES (:id, :document, :seq, :event, :actor, NOW())',
            [
                'id' => $id,
                'document' => $documentId,
                'seq' => $seq,
                'event' => CustodyEvent::Received->value,
                'actor' => $actorId,
            ],
        );

        return $id;
    }

    /**
     * @return array{uploader_id: string, state: string, idempotency_key_hash: ?string, digest: string}
     */
    private function documentRow(string $id): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT uploader_id, state, idempotency_key_hash, digest FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertIsArray($row, 'the document row exists');
        $hash = $row['idempotency_key_hash'] ?? null;

        return [
            'uploader_id' => $this->stringOf($row['uploader_id'] ?? null),
            'state' => $this->stringOf($row['state'] ?? null),
            'idempotency_key_hash' => null === $hash ? null : $this->stringOf($hash),
            'digest' => $this->stringOf($row['digest'] ?? null),
        ];
    }

    /**
     * @return list<array{seq: int, actor_id: string}>
     */
    private function custodyRows(string $documentId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT seq, actor_id FROM document_custody WHERE document_id = CAST(:id AS UUID) ORDER BY seq',
            ['id' => $documentId],
        );

        return \array_map(
            fn (array $row): array => [
                'seq' => $this->intOf($row['seq'] ?? null),
                'actor_id' => $this->stringOf($row['actor_id'] ?? null),
            ],
            $rows,
        );
    }

    /**
     * @return list<string> the actor of each custody row of the document, in sequence order
     */
    private function actorsOf(string $documentId): array
    {
        return \array_column($this->custodyRows($documentId), 'actor_id');
    }

    private function rowsIn(string $table): int
    {
        return $this->intOf($this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table)));
    }

    private function stringOf(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function intOf(mixed $value): int
    {
        $this->assertIsNumeric($value);

        return (int) $value;
    }
}
