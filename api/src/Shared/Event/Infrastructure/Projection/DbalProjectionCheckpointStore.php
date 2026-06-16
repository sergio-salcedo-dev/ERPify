<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Projection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link ProjectionCheckpointStore} on the `projection_checkpoint` table via the default DBAL
 * connection, so the checkpoint advance commits in the same transaction as the read-model writes.
 */
#[AsAlias(ProjectionCheckpointStore::class)]
final readonly class DbalProjectionCheckpointStore implements ProjectionCheckpointStore
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function lockAndRead(string $name): int
    {
        // Ensure the row exists (idempotent), then take a row lock that serialises concurrent runs of
        // the same projection until the surrounding transaction commits.
        $this->connection->executeStatement(
            'INSERT INTO projection_checkpoint (name, last_sequence, updated_at) VALUES (:name, 0, now()) '
            . 'ON CONFLICT (name) DO NOTHING',
            ['name' => $name],
        );

        $lastSequence = $this->connection->fetchOne(
            'SELECT last_sequence FROM projection_checkpoint WHERE name = :name FOR UPDATE',
            ['name' => $name],
        );

        return \is_numeric($lastSequence) ? (int) $lastSequence : 0;
    }

    #[Override]
    public function advance(string $name, int $sequence): void
    {
        $this->connection->executeStatement(
            'UPDATE projection_checkpoint SET last_sequence = :sequence, updated_at = now() WHERE name = :name',
            ['name' => $name, 'sequence' => $sequence],
            ['sequence' => Types::BIGINT],
        );
    }

    #[Override]
    public function reset(string $name): void
    {
        $this->connection->executeStatement(
            'INSERT INTO projection_checkpoint (name, last_sequence, updated_at) VALUES (:name, 0, now()) '
            . 'ON CONFLICT (name) DO UPDATE SET last_sequence = 0, updated_at = now()',
            ['name' => $name],
        );
    }
}
