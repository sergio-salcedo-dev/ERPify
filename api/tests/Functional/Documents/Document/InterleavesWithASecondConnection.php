<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Throwable;

/**
 * A second connection that holds what one side of a pair holds mid-transaction, and the probe that tells a
 * flow WAITING on it (a short `lock_timeout`, SQLSTATE 55P03) from one that completed or failed for another
 * reason. Requires {@see PlantsUploadIntents} for the application's own connection.
 */
trait InterleavesWithASecondConnection
{
    private ?Connection $holder = null;

    /**
     * Whether `$operation` blocks on a lock held by the other connection, rather than completing or failing for
     * another reason: it runs under a short lock timeout and must fail with SQLSTATE 55P03 in its chain.
     */
    private function waits(callable $operation): bool
    {
        $this->intentConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            $operation();

            return false;
        } catch (Throwable $throwable) {
            for ($cause = $throwable; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                if (
                    \str_contains($cause->getMessage(), '55P03') || \str_contains(
                        $cause->getMessage(),
                        'lock timeout',
                    )
                ) {
                    return true;
                }
            }

            throw $throwable;
        } finally {
            $this->intentConnection()->executeStatement('SET lock_timeout = 0');
        }
    }

    private function insertDocumentFor(Connection $connection, string $scopeId): string
    {
        $id = Uuid::generate();
        $connection->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, uploader_id, state, digest, size_bytes, next_scan_at, '
            . 'created_at, updated_at) '
            . "VALUES (:id, :scope, :id, :uploader, 'received', :digest, 10, NOW(), NOW(), NOW())",
            ['id' => $id, 'scope' => $scopeId, 'uploader' => Uuid::generate(), 'digest' => \hash('sha256', $id)],
        );

        return $id;
    }

    private function holder(): Connection
    {
        return $this->holder ??= DriverManager::getConnection($this->intentConnection()->getParams());
    }

    private function releaseTheHolder(): void
    {
        if ($this->holder instanceof Connection && $this->holder->isTransactionActive()) {
            $this->holder->rollBack();
        }

        $this->holder?->close();
        $this->holder = null;
        $this->intentConnection()->executeStatement('SET lock_timeout = 0');
    }
}
