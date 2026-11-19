<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentDerivativeReleases;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The derivative release's statements on `document`, every judgement of time the database's. Its two markers are
 * written by plain statements guarded on `Erased`, so they can never move a row's state.
 */
#[AsAlias(DocumentDerivativeReleases::class)]
final readonly class DbalDocumentDerivativeReleases implements DocumentDerivativeReleases
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function awaitingDerivativeRelease(int $rerequestAfterSeconds, int $limit, ?string $afterId = null): array
    {
        $after = null === $afterId ? '' : ' AND id > CAST(:after AS UUID)';
        $parameters = ['erased' => DocumentState::Erased->value, 'wait' => $rerequestAfterSeconds, 'limit' => $limit];

        if (null !== $afterId) {
            Uuid::ensure($afterId);
            $parameters['after'] = \strtolower($afterId);
        }

        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM document WHERE state = :erased AND derivatives_released_at IS NULL '
            . 'AND (derivatives_requested_at IS NULL '
            . 'OR derivatives_requested_at < NOW() - make_interval(secs => :wait))'
            . $after . ' ORDER BY id LIMIT :limit',
            $parameters,
            ['wait' => Types::INTEGER, 'limit' => Types::INTEGER],
        );
    }

    #[Override]
    public function markDerivativesRequested(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === $this->connection->executeStatement(
            'UPDATE document SET derivatives_requested_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID) AND state = :erased AND derivatives_released_at IS NULL',
            ['id' => \strtolower($documentId), 'erased' => DocumentState::Erased->value],
        );
    }

    #[Override]
    public function markDerivativesReleased(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === $this->connection->executeStatement(
            'UPDATE document SET derivatives_released_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID) AND state = :erased AND derivatives_released_at IS NULL',
            ['id' => \strtolower($documentId), 'erased' => DocumentState::Erased->value],
        );
    }

    #[Override]
    public function derivativesOverdue(int $olderThanSeconds, int $limit): array
    {
        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT d.id FROM document d WHERE d.state = :erased AND d.derivatives_released_at IS NULL AND EXISTS ('
            . 'SELECT 1 FROM document_custody c WHERE c.document_id = d.id AND c.event = :event '
            . 'AND c.created_at < NOW() - make_interval(secs => :age)) ORDER BY d.id LIMIT :limit',
            [
                'erased' => DocumentState::Erased->value,
                'event' => CustodyEvent::Erased->value,
                'age' => $olderThanSeconds,
                'limit' => $limit,
            ],
            ['age' => Types::INTEGER, 'limit' => Types::INTEGER],
        );
    }
}
