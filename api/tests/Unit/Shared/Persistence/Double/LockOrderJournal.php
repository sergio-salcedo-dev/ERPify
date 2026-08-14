<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

/**
 * The sequence of tables a unit of work reaches for, in the order it reaches for them.
 *
 * A cross-table deadlock is a cycle in a wait-for graph, and the only thing a transaction contributes to that
 * graph is the ORDER in which it acquires. That order is what this records, and it is the half a call-count
 * assertion cannot see: a statement that runs second orders nothing that has not already happened, so a test
 * asserting only that the statement ran passes over the single arrangement the operation exists to forbid.
 *
 * It is written by the in-memory repositories, each of which stands in for one table's adapter and names it.
 * What the entry means is "the statement that takes this table's row lock ran here" — never "a lock was
 * observed", which no in-memory double can honestly claim. That second claim needs a real Postgres and a
 * second connection; here it is
 * {@see \Erpify\Tests\Functional\Iam\Identity\ErasureLockOrderFunctionalTest}.
 *
 * @internal
 */
final class LockOrderJournal
{
    public const string IDENTITY_USER = 'identity_user';

    public const string IAM_INVITATION = 'iam_invitation';

    public const string PASSWORD_RESET_TOKEN = 'identity_password_reset_token';

    /**
     * Repeats are kept rather than collapsed: a path that takes one table's lock twice around another's is a
     * different arrangement from one that takes it once, and folding them would hide the difference.
     *
     * @var list<string>
     */
    public array $tablesLockedInOrder = [];

    public function locked(string $table): void
    {
        $this->tablesLockedInOrder[] = $table;
    }

    /**
     * The same sequence with CONSECUTIVE repeats folded — the cross-table order, which is the whole of what a
     * cross-table deadlock can be built from.
     *
     * This does not contradict the property above, it is its complement. Taking one table twice AROUND
     * another (`A B A`) is a different arrangement and survives here untouched, because those repeats are not
     * consecutive. Taking it twice in a ROW (`A A B`) cannot contribute an edge no single acquisition already
     * contributes — the second one waits on nothing the first did not already hold. Asserting the raw list
     * therefore pins how MANY times a path acquires as well as in what order, and the count is implementation:
     * a use case that reads a row under `FOR UPDATE` and then deletes it acquires twice where one that only
     * deletes acquires once, with the same order and the same safety.
     *
     * @return list<string>
     */
    public function crossTableOrder(): array
    {
        $order = [];

        foreach ($this->tablesLockedInOrder as $tableLockedInOrder) {
            if ($tableLockedInOrder !== \end($order)) {
                $order[] = $tableLockedInOrder;
            }
        }

        return $order;
    }
}
