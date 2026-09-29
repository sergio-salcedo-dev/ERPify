<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Application\IdentityRowLock;
use Erpify\Iam\Identity\Domain\Email;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see IdentityRowLock} over plain DBAL: a `SELECT … FOR UPDATE` on `identity_user` inside a transaction the
 * connection opens itself, so no `User` is hydrated and nothing pending in the entity manager is flushed. The
 * statement carries no column the caller does not need — `1` for the id form, the id for the address form — so
 * the lock reads no credential digest into this process.
 *
 * **The wait is bounded by `lock_timeout`, and only when this transaction is the outermost one.** An erasure holds
 * the subject's row from its administrator re-check to its commit, and a writer waiting on it runs on a login
 * refusal or at `kernel.terminate`, where an unbounded wait would hold the request or the worker for as long as
 * the erasure runs. On timeout Postgres raises `55P03`, the transaction rolls back and the caller reports a
 * skipped projection. `SET LOCAL` lasts until the end of the TOP-LEVEL transaction, so inside a caller's open
 * transaction — where DBAL nests this one as a savepoint — it would leak into statements this class does not own;
 * there the bound is left to whoever opened that transaction.
 */
#[AsAlias(IdentityRowLock::class)]
final readonly class DbalIdentityRowLock implements IdentityRowLock
{
    /**
     * Long enough to outlast the ordinary contention on one identity's row — another failed attempt's counter
     * write, an administrator's status change — and short enough that a request never waits out a whole erasure.
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
                    FOR UPDATE
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
                    FOR UPDATE
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
        $outermost = !$this->connection->isTransactionActive();

        return $this->connection->transactional(function () use ($outermost, $body): bool {
            if ($outermost) {
                $this->connection->executeStatement(self::BOUND_THE_WAIT);
            }

            return $body();
        });
    }
}
