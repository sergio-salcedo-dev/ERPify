<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Health\Domain\DatabaseHealthChecker;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Probes the default Doctrine connection with a trivial `SELECT 1` round-trip.
 * Any failure (connection refused, auth error, timeout) is swallowed and
 * reported as unavailable — the caller surfaces it as a health status, so a
 * down database must never escape as an unhandled exception here.
 */
#[AsAlias(DatabaseHealthChecker::class)]
final readonly class DoctrineDatabaseHealthChecker implements DatabaseHealthChecker
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function isAvailable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
