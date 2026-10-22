<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Application\IdentityRowLock;
use Erpify\Iam\Identity\Domain\Email;
use LogicException;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see IdentityRowLock} over plain DBAL: a `SELECT … FOR KEY SHARE` on `identity_user` inside a transaction the
 * connection opens itself, so no `User` is hydrated and nothing pending in the entity manager is flushed. The
 * statement carries no column the caller does not need — `1` for the id form, the id for the address form — so
 * the lock reads no credential digest into this process.
 *
 * **`FOR KEY SHARE` is the weakest mode that still serialises against the erasure**, which is all the port owes.
 * It conflicts with the erasure's `FOR UPDATE` and with its `DELETE`, and once the erasure commits Postgres
 * re-reads the row and finds none. It does not conflict with another writer holding the same mode, nor with a
 * plain `UPDATE` that leaves the key alone, so two late writers for one subject never queue behind each other;
 * what still makes one wait is a transaction that took the row `FOR UPDATE` — the erasure, and the locked reads
 * the login and administration paths decide on. A wait that exhausts the bound costs an audit row, so every wait
 * the guarantee does not need is one fewer row lost.
 *
 * **The wait is bounded by `lock_timeout`.** An erasure holds the subject's row from its administrator re-check
 * to its commit, and a writer waiting on it runs on a login refusal or at `kernel.terminate`, where an unbounded
 * wait would hold the request or the worker for as long as the erasure runs. On timeout Postgres raises `55P03`,
 * the transaction rolls back and the caller reports a skipped projection.
 *
 * **A call inside an open transaction is refused, not nested.** DBAL would nest it as a savepoint, and both
 * halves of the contract would then be false at once: `SET LOCAL` lasts until the end of the TOP-LEVEL
 * transaction, so the bound would leak into statements this class does not own, and the row written under the
 * lock would commit or roll back with the caller's business transaction rather than on its own. Every caller is
 * post-commit, so an open transaction here is a wiring defect, and a `LogicException` says so — the callers
 * swallow it into their skipped-projection report, where it is seen rather than silently rebound.
 */
#[AsAlias(IdentityRowLock::class)]
final readonly class DbalIdentityRowLock implements IdentityRowLock
{
    /**
     * Long enough to outlast the ordinary `FOR UPDATE` holders of one identity's row — a failed attempt's locked
     * read and counter write, an administrator's status change — and short enough that a request never waits out
     * a whole erasure.
     */
    private const string BOUND_THE_WAIT = "SET LOCAL lock_timeout = '2s'";

    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function whileHeld(string $userId, callable $operation): bool
    {
        return $this->inOwnTransaction(function () use ($userId, $operation): bool {
            $held = false !== $this->connection->fetchOne(
                <<<'SQL'
                    SELECT 1
                    FROM identity_user
                    WHERE id = CAST(:userId AS UUID)
                    FOR KEY SHARE
                    SQL,
                ['userId' => $userId],
            );

            if ($held) {
                $operation();
            }

            return $held;
        });
    }

    #[Override]
    public function whileHeldByEmail(#[SensitiveParameter] Email $email, callable $operation): void
    {
        $this->inOwnTransaction(function () use ($email, $operation): bool {
            $userId = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id::text
                    FROM identity_user
                    WHERE email = :email
                    FOR KEY SHARE
                    SQL,
                ['email' => $email->toString()],
            );

            $operation(\is_string($userId) ? $userId : null);

            return true;
        });
    }

    /**
     * @param callable(): bool $body
     */
    private function inOwnTransaction(callable $body): bool
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException(
                'An identity row lock runs in a transaction of its own; it was called inside an open one.',
            );
        }

        return $this->connection->transactional(function () use ($body): bool {
            $this->connection->executeStatement(self::BOUND_THE_WAIT);

            return $body();
        });
    }
}
