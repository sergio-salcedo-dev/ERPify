<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Rolls back a transaction a unit of work left open on the shared DBAL connection when its boundary ends — a
 * request, or a worker message — and reports it at `critical`.
 *
 * Under FrankenPHP worker mode, and in a Messenger worker, the connection outlives the unit of work that used
 * it, and nothing between two units closes a transaction: DoctrineBundle's reset clears the entity managers
 * and leaves the connection as it is. So a transaction one request forgot keeps every row lock it took, every
 * later write on that worker lands INSIDE it, and none of it commits until the worker recycles — each audited
 * boundary served there answers 5xx on the way. Rolling back at the boundary confines the damage to the unit
 * that leaked: its own uncommitted writes are lost, which is the outcome it already had, and the next one
 * starts on a clean connection.
 *
 * **Down to a baseline, never to zero.** The caller passes the nesting level it observed when the unit began,
 * because a transaction may legitimately span the boundary — a functional test opens one around the requests
 * it makes — and that one is not this class's to end.
 *
 * **A counted loop, never `while (isTransactionActive())`.** With `autoCommit` off, a top-level `rollBack()`
 * begins the next transaction itself, so a loop keyed on "still active" never ends.
 *
 * **`close()` is the fallback, not the method.** It drops the handle, which makes the server roll back, but it
 * leaves DBAL's rollback-only flag as it was, so the next unit's first commit would fail for a reason it did not
 * cause. After a close, a bare begin/rollback on the fresh handle clears that flag.
 *
 * **Reported on the always-on channel.** A `critical` on the default channel would activate the prod
 * `fingers_crossed` handler and flush the request's buffered records with it — the email `ContextListener`
 * writes on every authenticated request among them. The context carries the route or message class, never a
 * path or a payload.
 */
final readonly class LeakedTransactionContainment
{
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function nestingLevel(): int
    {
        return $this->connection->getTransactionNestingLevel();
    }

    /**
     * @param array<string, scalar|null> $context names the unit that leaked; never a request path or a message
     *                                            payload, both of which can carry a person's identifier
     *
     * @return int the number of levels found open above the baseline (0 when nothing leaked)
     */
    public function containAbove(int $baseline, array $context): int
    {
        $leaked = $this->connection->getTransactionNestingLevel() - $baseline;

        if ($leaked <= 0) {
            return 0;
        }

        $closed = false;

        try {
            for ($level = $leaked; $level > 0; --$level) {
                $this->connection->rollBack();
            }
        } catch (Throwable $throwable) {
            $closed = true;
            $this->close($throwable);
        }

        $this->logger->critical(
            'A unit of work ended with a database transaction still open; it was rolled back.',
            [...$context, 'leaked_levels' => $leaked, 'connection_closed' => $closed],
        );

        return $leaked;
    }

    private function close(Throwable $cause): void
    {
        $this->connection->close();

        try {
            $this->connection->beginTransaction();
            $this->connection->rollBack();
        } catch (Throwable $throwable) {
            $this->logger->critical(
                'The connection could not be reset after a leaked transaction failed to roll back.',
                ['exception' => $throwable, 'cause' => $cause],
            );
        }
    }
}
