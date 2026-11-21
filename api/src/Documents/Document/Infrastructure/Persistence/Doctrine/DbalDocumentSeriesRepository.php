<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Projection\DocumentVersionRow;
use Erpify\Documents\Document\Domain\Repository\DocumentSeriesRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use UnexpectedValueException;

/**
 * The series projection in plain DBAL, read straight off `document` with no lock.
 *
 * **Order is creation, `(created_at, id)`.** `created_at` keeps whole seconds, so two versions filed in one second
 * fall to the id, a UUID v7 whose leading bits are its mint time; the cursor is the last version held, compared as
 * the same pair. The uploader of an `Erased` version is replaced with NULL inside the statement, so it never leaves the
 * database for a tombstone.
 */
#[AsAlias(DocumentSeriesRepository::class)]
final readonly class DbalDocumentSeriesRepository implements DocumentSeriesRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function versionsOf(string $seriesId, ?string $afterId, int $limit): array
    {
        Uuid::ensure($seriesId);

        if (null !== $afterId) {
            Uuid::ensure($afterId);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, d.predecessor_id, d.state, d.digest, d.size_bytes, d.retention_reason, d.legal_basis, '
            . 'd.review_at, CASE WHEN d.state = :erased THEN NULL ELSE d.uploader_id END AS uploader_id, d.created_at '
            . 'FROM document d WHERE d.series_id = CAST(:series AS UUID) '
            . 'AND (CAST(:after AS UUID) IS NULL OR (d.created_at, d.id) > ('
            . 'SELECT a.created_at, a.id FROM document a '
            . 'WHERE a.id = CAST(:after AS UUID) AND a.series_id = CAST(:series AS UUID))) '
            . 'ORDER BY d.created_at, d.id LIMIT :limit',
            [
                'series' => \strtolower($seriesId),
                'after' => null === $afterId ? null : \strtolower($afterId),
                'erased' => DocumentState::Erased->value,
                'limit' => \max(1, $limit),
            ],
            ['limit' => Types::INTEGER],
        );

        return \array_map($this->rowOf(...), $rows);
    }

    #[Override]
    public function unerasedNewestFirst(string $seriesId): array
    {
        Uuid::ensure($seriesId);

        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM document WHERE series_id = CAST(:series AS UUID) AND state <> :erased '
            . 'ORDER BY created_at DESC, id DESC',
            ['series' => \strtolower($seriesId), 'erased' => DocumentState::Erased->value],
        );
    }

    #[Override]
    public function exists(string $seriesId): bool
    {
        Uuid::ensure($seriesId);

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM document WHERE series_id = CAST(:series AS UUID) LIMIT 1',
            ['series' => \strtolower($seriesId)],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowOf(array $row): DocumentVersionRow
    {
        $reviewAt = $row['review_at'] ?? null;
        $predecessorId = $row['predecessor_id'] ?? null;
        $uploaderId = $row['uploader_id'] ?? null;

        return new DocumentVersionRow(
            $this->stringOf($row['id'] ?? null),
            null === $predecessorId ? null : $this->stringOf($predecessorId),
            DocumentState::from($this->stringOf($row['state'] ?? null)),
            $this->stringOf($row['digest'] ?? null),
            $this->intOf($row['size_bytes'] ?? null),
            $this->nullableStringOf($row['retention_reason'] ?? null),
            $this->nullableStringOf($row['legal_basis'] ?? null),
            null === $reviewAt ? null : new DateTimeImmutable($this->stringOf($reviewAt)),
            null === $uploaderId ? null : $this->stringOf($uploaderId),
            new DateTimeImmutable($this->stringOf($row['created_at'] ?? null)),
        );
    }

    private function stringOf(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException('A document column did not read back as a string.');
        }

        return $value;
    }

    private function nullableStringOf(mixed $value): ?string
    {
        return null === $value ? null : $this->stringOf($value);
    }

    private function intOf(mixed $value): int
    {
        return \is_numeric($value)
            ? (int) $value
            : throw new UnexpectedValueException('A document column did not read back as an integer.');
    }
}
