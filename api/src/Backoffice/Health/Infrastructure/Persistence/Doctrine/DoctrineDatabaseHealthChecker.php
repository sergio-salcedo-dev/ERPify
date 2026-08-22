<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Health\Domain\DatabaseHealthChecker;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Probes the default Doctrine connection with a trivial `SELECT 1`, bounded by a
 * per-probe `statement_timeout`. A reachable-but-stuck database (saturated pool,
 * lock contention, a wedged backend) would otherwise let the round-trip hang well
 * past the always-200 health request's own deadline. The timeout is applied with
 * `SET LOCAL` inside a transaction, so it governs only this probe and is discarded
 * on commit/rollback — it never leaks to the next user of the pooled connection.
 *
 * Any failure (connection refused, auth error, statement timeout) is logged at
 * warning level — the request still completes and the caller degrades it to a
 * health status, so a down database must never escape as an unhandled exception.
 * Bounding the connect phase itself (an unreachable host) is the DSN's job
 * (`connect_timeout`), not this adapter's.
 *
 * The line goes to the always-on `observability` channel because the endpoint answers 200 either way, with
 * `status: ok|error` and no reason — so the log is the only place the CAUSE of a degraded probe exists, and
 * on the buffered channel a `warning` there is discarded outright.
 *
 * Two costs, named rather than discovered later. Volume is unbounded: one line per external probe for as
 * long as an outage lasts, on a stream with a size bound but no TTL and no declared owner of its deletion. And a DBAL
 * connection exception carries the DSN host, port and user in its message — infrastructure detail, not a
 * person's, but it is written to that stream on every failed probe.
 */
#[AsAlias(DatabaseHealthChecker::class)]
final readonly class DoctrineDatabaseHealthChecker implements DatabaseHealthChecker
{
    private const string SET_PROBE_TIMEOUT = "SET LOCAL statement_timeout = '2s'";

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function isHealthy(): bool
    {
        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement(self::SET_PROBE_TIMEOUT);
            $this->connection->executeQuery('SELECT 1');
            $this->connection->commit();

            return true;
        } catch (Throwable $throwable) {
            $this->rollBackQuietly();
            $this->logger->warning('Database health probe failed', ['exception' => $throwable]);

            return false;
        }
    }

    private function rollBackQuietly(): void
    {
        try {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        } catch (Throwable) {
            // A lost connection cannot be rolled back; the probe already reports
            // unavailable, so there is nothing further to recover here.
        }
    }
}
