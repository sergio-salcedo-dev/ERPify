<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the one statement that orders every `identity_user` acquisition.
 *
 * It exists because that statement's clauses are load-bearing and **none of them is reachable by a behavioural
 * test**. Delete `ORDER BY id` and: every verdict test still returns the same boolean, because the answer is an
 * `array_any` over an unordered list; both `pg_locks` tests still see their `RowShareLock`, because that mode is
 * relation-level and any `FOR UPDATE` takes it; and every Behat round-trip canary still counts one statement.
 * The build stays green and the ABBA the clause prevents — two concurrent last-two-admin transitions walking the
 * same rows in opposite orders under different plans — is fully reachable again.
 *
 * That is why this reads the source. A textual gate is the weakest instrument in the repo and it is used here
 * because the alternatives were measured and do not reach: the acquisition ORDER is only observable against a
 * concurrent contender, which needs two racing transactions rather than one holding and one probing, and the
 * lock-only member returns `void` by design so nothing about its result can be asserted.
 *
 * **What a green proves:** the statement still carries the predicate, the ordering and the lock. Nothing more.
 * It does not prove the statement runs, that any caller reaches it, or that Postgres locks in the order it
 * sorts — that last one rests on `LockRows` sitting above `Sort` in the plan, which is an argument, not a
 * measurement. What proves a caller reaches it is `DoctrineActiveAdministratorDirectoryTest`; what proves the
 * callers take it before their target row is `AdministratorSetLockOrderTest`.
 *
 * @internal
 */
#[CoversNothing]
final class AdministratorSetLockStatementGateTest extends TestCase
{
    private const string ADAPTER = __DIR__
        . '/../../../../src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php';

    #[Test]
    public function theActiveAdministratorSetIsSelectedOrderedAndLocked(): void
    {
        $source = $this->adapterSource();

        // Order matters in the assertion as well as in the SQL: `FOR UPDATE` before `ORDER BY` is a syntax
        // error Postgres would reject, but a sort applied to a statement that locks nothing, or a lock over an
        // unsorted scan, are both silently wrong — so the three clauses are pinned as a sequence.
        $this->assertMatchesRegularExpression(
            '/SELECT\s+id\s+FROM\s+identity_user\s+WHERE\b.*?\bORDER\s+BY\s+id\s+FOR\s+UPDATE/s',
            $source,
            'The active-admin set is no longer selected as `… ORDER BY id FOR UPDATE`. Both clauses carry the '
            . 'invariant: without the lock the set is a stale read two transitions can both pass, and without '
            . 'the ordering Postgres promises no stable scan order across plans, so two callers can walk the '
            . 'same rows in opposite directions and deadlock. Nothing else in the suite goes red on either.',
        );
    }

    #[Test]
    public function theOrderingClauseIsNotWrittenTwice(): void
    {
        // Two copies is how the clause drifts: one caller gets fixed, the other keeps an old predicate, and the
        // two lock sets diverge — which is the same defect as no ordering at all, reached by a different route.
        $this->assertSame(
            1,
            \preg_match_all('/\bORDER\s+BY\s+id\s+FOR\s+UPDATE/', $this->adapterSource()),
            'The active-admin set is locked by more than one statement, so the two can drift apart.',
        );
    }

    private function adapterSource(): string
    {
        $path = \realpath(self::ADAPTER);

        $this->assertIsString($path, 'The adapter this gate reads has moved; the gate is asserting nothing.');

        return (string) \file_get_contents($path);
    }
}
