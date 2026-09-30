<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Rolls back a transaction a unit of work left open on the default DBAL connection when its boundary ends — a
 * request, or a worker message — and reports it at `critical`.
 *
 * Under FrankenPHP worker mode, and in a Messenger worker, the connection outlives the unit of work that used
 * it, and nothing between two units closes a transaction: DoctrineBundle's reset clears the entity managers
 * and leaves the connection as it is. So a transaction one request forgot keeps every row lock it took, every
 * later write on that worker lands INSIDE it, and none of it commits until the worker recycles. Rolling back at
 * the boundary confines the damage to the unit that leaked: its own uncommitted writes are lost, which is the
 * outcome it already had.
 *
 * **Down to a baseline.** The caller passes the nesting level the unit is entitled to leave open. Outside `test`
 * that is always zero, because nothing legitimately spans a request or a message there; under `test` it is the
 * level observed when the unit began, because a functional test may open a transaction around the requests it
 * makes, and that one is not this class's to end.
 *
 * **A counted loop, never `while (isTransactionActive())`.** With `autoCommit` off, a top-level `rollBack()`
 * begins the next transaction itself, so a loop keyed on "still active" never ends.
 *
 * **`close()` is the fallback, and only above a zero baseline.** It drops the handle, which makes the server roll
 * back, and it zeroes the nesting level — so above a non-zero baseline it would end the transaction the caller
 * kept, and there the failure is rethrown instead. It also leaves DBAL's rollback-only flag as it was, so the
 * next unit's first commit would fail for a reason it did not cause; a bare begin/rollback on the fresh handle
 * clears it. That reset assumes `autoCommit` on, as this application runs.
 *
 * **Reported on the always-on channel, and never at the caller's expense.** A `critical` on the default channel
 * would activate the prod `fingers_crossed` handler and flush the request's buffered records with it — the email
 * `ContextListener` writes on every authenticated request among them. The context carries the route or message
 * class, never a path or a payload. A logger that throws is swallowed: the worker boundary promises never to
 * throw, and a report is worth less than the rollback it describes.
 *
 * Only the default connection is covered; this application maps one.
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

        try {
            for ($level = $leaked; $level > 0; --$level) {
                $this->connection->rollBack();
            }
        } catch (Throwable $throwable) {
            if ($baseline > 0) {
                $this->report($context + ['leaked_levels' => $leaked, 'connection_closed' => false], $throwable);

                throw $throwable;
            }

            $this->report($context + ['leaked_levels' => $leaked, 'connection_closed' => true], $throwable);
            $this->close();

            return $leaked;
        }

        $this->report($context + ['leaked_levels' => $leaked, 'connection_closed' => false]);

        return $leaked;
    }

    private function close(): void
    {
        $this->connection->close();

        try {
            $this->connection->beginTransaction();
            $this->connection->rollBack();
        } catch (Throwable $throwable) {
            $this->safely(fn () => $this->logger->critical(
                'The connection could not be reset after a leaked transaction failed to roll back.',
                ['exception' => $throwable],
            ));
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function report(array $context, ?Throwable $rollbackFailure = null): void
    {
        if ($rollbackFailure instanceof Throwable) {
            $context['exception'] = $rollbackFailure;
        }

        $this->safely(fn () => $this->logger->critical(
            'A unit of work ended with a database transaction still open; it was rolled back.',
            $context,
        ));
    }

    private function safely(callable $report): void
    {
        try {
            $report();
        } catch (Throwable) {
            // The rollback already happened; losing its report must not undo that by failing the caller.
        }
    }
}
