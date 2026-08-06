<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use Throwable;

/**
 * {@see TransactionManager} that runs the unit of work inline like its sibling {@see InlineTransactionManager}
 * and, in addition, counts the boundaries it opened and how each one ended.
 *
 * It exists for the one property an inline double cannot otherwise express: that revoking several invitations
 * is ALL-OR-NOTHING. No in-memory double can undo writes the way Postgres does, so asserting "nothing was
 * revoked" against the store would only be asserting the double's own generosity. What decides the real
 * outcome is the shape of the boundary — one `transactional()` around every write, left by the throwable —
 * because a loop that opened one transaction per invitation would commit the earlier ones and leave a
 * partially-pulled set behind. That is exactly what these counters make falsifiable.
 *
 * @internal
 */
final class CountingTransactionManager implements TransactionManager
{
    public int $opened = 0;

    public int $committed = 0;

    public int $abandoned = 0;

    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->opened;

        try {
            $result = $operation();
        } catch (Throwable $throwable) {
            ++$this->abandoned;

            throw $throwable;
        }

        ++$this->committed;

        return $result;
    }
}
