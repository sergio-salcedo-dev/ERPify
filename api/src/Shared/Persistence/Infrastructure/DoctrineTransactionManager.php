<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The Doctrine adapter for {@see TransactionManager}: delegates to {@see EntityManagerInterface::wrapInTransaction},
 * which begins a transaction, flushes and commits on success, and rolls back on any throwable. Confining the
 * EntityManager here keeps orchestration (Application) free of the ORM and is the deptrac-clean seam that
 * pre-existing `wrapInTransaction`-in-Application usages are being ratcheted towards.
 *
 * It is also where a retryable database failure becomes an HTTP contract answer, and this is the only place
 * in the app that can be: a deadlock is by definition transaction-scoped, so the transaction boundary is
 * exactly its extent, and {@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory} lives in
 * Application, where a Doctrine import would be a new inner-layer framework dependency deptrac ratchets
 * against. **A caller still holding the EntityManager and calling `wrapInTransaction` itself is not
 * covered** — that is the grandfathered debt this seam exists to absorb, and paying it down closes this gap
 * with it.
 */
#[AsAlias(TransactionManager::class)]
final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * DBAL's own marker is what is caught, rather than a list of SQLSTATEs: `RetryableException` means
     * exactly "retrying the transaction makes sense", which is the claim being translated. On PostgreSQL it
     * covers `40P01` (deadlock detected) and `40001` (serialization failure), both of which arrive as
     * `DeadlockException`. Untranslated they surface as a bare 500 `unhandled-exception`, telling a client
     * the server is broken when the correct answer is "try that again".
     *
     * The original is kept as `previous`, so the driver exception and its SQLSTATE survive into the
     * `dev`/`test` debug chain and into Sentry — which receives this class precisely because
     * `ServiceUnavailable` is the one marker that is not a `ClientError`.
     *
     * It does **not** reach the per-error log line. That record is built from the thrown exception's own
     * class and message and walks no `previous` chain, so an operator reading prod stderr sees
     * `transient-transaction-failure` and cannot tell `40P01` from `40001`. Widening it to the chain is not
     * free — a driver message carries the statement that failed — and the two SQLSTATEs share one response
     * and one remedy, so the distinction is left to the sinks that already hold the whole exception.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    #[Override]
    public function transactional(callable $operation): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction($operation);
        } catch (RetryableException $retryableException) {
            throw new TransientTransactionFailure($retryableException);
        } catch (ForeignKeyConstraintViolationException $foreignKeyViolation) {
            throw new ReferentialIntegrityViolation($foreignKeyViolation);
        } finally {
            $this->reopenIfStranded();
        }
    }

    /**
     * `wrapInTransaction` closes the entity manager on any failure, and nothing else reopens it: the listener
     * that recomposes the connection is registered for `dev`/`test` only, so under FrankenPHP worker mode a
     * manager closed by one request is still closed for the next. That turns the 503 this class answers into
     * an invitation to a retry that would fail differently — reads survive a closed manager, but the first
     * `flush` after it raises `EntityManagerClosed`, which is a 500.
     *
     * **Keyed on state, never on the exception type.** The reset belongs to whatever left the manager
     * stranded, not to one failure mode: by the time a nested unit of work rethrows, what reaches the outer
     * frame is already this class's own translation, so a type-keyed recovery would simply never match there.
     *
     * **Why the transaction check is the load-bearing half.** Units of work nest — a use case opens one and
     * calls another that opens its own. DBAL rolls the inner one back to a savepoint and leaves the outer
     * running, so resetting whenever the manager is closed would destroy work the caller is still inside.
     * A reset is owed only once nothing is running: manager closed AND no transaction left.
     */
    private function reopenIfStranded(): void
    {
        // A pure getter on the ORM side, so it is safe to ask a closed manager for its connection.
        if (!$this->entityManager->isOpen() && !$this->entityManager->getConnection()->isTransactionActive()) {
            $this->managerRegistry->resetManager();
        }
    }
}
