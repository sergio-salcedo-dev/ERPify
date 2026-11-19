<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asks the planner whether the prune's two statements actually acquire their locks in `id` order, and whether
 * the one with no `LIMIT` reads the table through an index.
 *
 * The clause the whole argument rests on is `FOR UPDATE`, and nothing behavioural can see it: the functional
 * cases assert which rows survive and which are cleared, which the predicates decide on their own, and
 * acquisition ORDER is not observable from a single holder — it needs a concurrent contender, not one
 * transaction holding and another probing. So this asks the planner instead, over the statements the class
 * actually emits rather than copies kept in a test, exactly as {@see ObservesRowLocksOnASecondConnection}
 * already does for the sibling `identity_user` lock.
 *
 * `EXPLAIN` alone, never `ANALYZE`: both statements carry `FOR UPDATE`, and executing them here would take
 * real locks and delete or rewrite real rows.
 *
 * This is the assertion `AuditPruneStatementGateTest` cannot make. That gate proves the clauses are still
 * written; this one proves they still do what they are written for — a `LockRows` node over rows the plan
 * reaches in `id` order. Either shape that establishes the order is accepted, because which one the planner
 * picks is a function of table statistics.
 *
 * @internal
 *
 * @phpstan-type BoundTypes array<string, ArrayParameterType|ParameterType|Type|string>
 * @phpstan-type EmittedStatement array{string, array<string, mixed>, BoundTypes}
 */
#[CoversNothing]
final class AuditPrunePlanFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const int BATCH = 5000;

    /** Non-exempt `security` rows spread across the year the window still covers. */
    private const int IN_WINDOW_ROWS = 20000;

    /** Evidence rows already past the `security` threshold — the only rows the metadata clear may reach. */
    private const int EXEMPT_PAST_THRESHOLD_ROWS = 200;

    /** Rows past the threshold the `DELETE` meets, one in ten of them evidence it has to skip. */
    private const int BACKLOG_ROWS = 20000;

    #[Test]
    public function theDeleteBatchAcquiresItsLocksInIdOrder(): void
    {
        [$sql, $parameters, $types] = $this->statementThePrunerEmits('DELETE');

        $plan = $this->planFor($sql, $parameters, $types, backlogRows: self::BACKLOG_ROWS);
        $this->assertStringContainsString(
            'LockRows',
            $plan,
            'The batching SELECT no longer locks the rows it selects, so its ordering orders nothing: the '
            . 'outer DELETE re-locks in the order of whatever node unique-ified the subquery. Plan: ' . $plan,
        );
        $this->assertOrdersById(
            $this->planFor($this->innerSelectOf($sql), $parameters, $types, backlogRows: self::BACKLOG_ROWS),
            'DELETE',
        );
    }

    /**
     * The clear carries no `LIMIT`, so nothing bounds what one run reads except the access path. Measured over
     * this population on PostgreSQL 18: a bitmap scan of `audit_log_level_idx (level, occurred_on)` sorted on
     * `id`, because after the `DELETE` of its level only evidence rows are past the threshold — the range the
     * index serves is every evidence row of that level ever written, already-cleared ones included, which grows
     * by one row per erasure. That is why the table carries no partial index over the exempt actions: the planner
     * already avoids the table, and an index tied to {@see \Erpify\Shared\Audit\Domain\AuditErasureEvidence}
     * would have to be rewritten by a migration whenever that set changes.
     */
    #[Test]
    public function theMetadataClearAcquiresItsLocksInIdOrderThroughAnIndex(): void
    {
        [$sql, $parameters, $types] = $this->statementThePrunerEmits('UPDATE');

        $plan = $this->planFor($sql, $parameters, $types);
        $this->assertStringContainsString(
            'LockRows',
            $plan,
            'The metadata clear no longer locks the rows it selects, so its ordering orders nothing: the outer '
            . 'UPDATE locks in the order of whatever node unique-ified the subquery, and the clear can deadlock '
            . 'against the actor-axis erasure, which acquires ascending by `id`. Plan: ' . $plan,
        );
        // The whole statement too, not only its subquery: the outer side may join the subquery's ids against
        // a sequential scan of `audit_log` instead of probing the primary key.
        $this->assertStringNotContainsString(
            '"Seq Scan"',
            $plan,
            'The metadata clear joins against a sequential scan of `audit_log`. It runs daily with no LIMIT, so '
            . 'that is a full read of the largest table in the schema under the prune lock. Plan: ' . $plan,
        );

        $inner = $this->planFor($this->innerSelectOf($sql), $parameters, $types);
        $this->assertOrdersById($inner, 'UPDATE');
        $this->assertStringNotContainsString(
            '"Seq Scan"',
            $inner,
            'The metadata clear reads `audit_log` with a sequential scan. It runs daily with no LIMIT, so that '
            . 'is a full read of the largest table in the schema under the prune lock. Plan: ' . $inner,
        );
    }

    /**
     * Explained on its own, because the outer statement probes the same table by primary key and that node
     * would match the ordering assertion whether or not the subquery still carried `ORDER BY id`.
     */
    private function assertOrdersById(string $innerPlan, string $statement): void
    {
        // The sort key is matched qualified-or-bare, as Postgres prints it either way.
        $this->assertMatchesRegularExpression(
            '/"Sort Key": \["(?:\w+\.)?id"\]|audit_log_pkey/',
            $innerPlan,
            \sprintf(
                'The %s no longer establishes an order over the rows it locks: its subquery has neither a Sort '
                . 'on `id` nor a primary-key walk. It can then take rows in the opposite direction to the '
                . 'actor-axis erasure, which acquires ascending by `id`, and the two deadlock. Plan: %s',
                $statement,
                $innerPlan,
            ),
        );
    }

    /**
     * The subquery the outer statement wraps, taken from the emitted statement rather than restated so a
     * change to its shape reaches this assertion instead of going around it.
     */
    private function innerSelectOf(string $statement): string
    {
        $opened = \strpos($statement, '(SELECT');
        $this->assertIsInt($opened, 'the emitted statement has no subquery to explain');

        $inner = \rtrim(\substr($statement, $opened + 1));
        $this->assertStringEndsWith(')', $inner);

        return \substr($inner, 0, -1);
    }

    /**
     * The SQL {@see DbalAuditLogPruner} emits for the `security` level, with the parameters and types it binds,
     * captured from the class itself — asking the planner about a string kept in a test would only prove the
     * test's own copy is ordered and locked.
     *
     * @return EmittedStatement
     */
    private function statementThePrunerEmits(string $verb): array
    {
        /** @var list<EmittedStatement> $emitted */
        $emitted = [];
        // Stubs, not mocks: nothing here is an expectation about how the class is called. `fetchOne` grants
        // the advisory lock, and `executeStatement` returns a short batch so the drain loop ends after one
        // pass — the statements it emits are the whole subject.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = [], array $types = []) use (&$emitted): int {
                /** @var EmittedStatement $statement */
                $statement = [$sql, $parameters, $types];
                $emitted[] = $statement;

                return 0;
            },
        );

        $thresholds = (new AuditRetentionPolicy(90, 365))->thresholdsAt(new DateTimeImmutable(self::ANCHOR));
        (new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new NullLogger(), self::BATCH))
            ->prune(...$thresholds)
        ;

        foreach ($emitted as $statement) {
            if (
                \str_starts_with($statement[0], $verb . ' ')
                && AuditLevel::SECURITY->value === ($statement[1]['level'] ?? null)
            ) {
                return $statement;
            }
        }

        $this->fail(\sprintf('the pruner emitted no %s at security to explain', $verb));
    }

    /**
     * The plan is a costed choice, so asking about it over whatever the shared database happens to hold makes
     * the answer a property of the ambient data rather than of the statement — measured on the sibling
     * `FailedMessagePrunePlanFunctionalTest`, where the same mutation was green under one population and red
     * under another. So the population is seeded here: a year of ordinary `security` rows and a tail of
     * evidence rows past the threshold, which is what the clear meets after the `DELETE` of its level, plus —
     * for the `DELETE` — a backlog of ordinary rows past the threshold, so its batch is a real sweep rather
     * than an empty drain. It is `ANALYZE`d so the planner is not costing against stale statistics, then rolled
     * back. The rollback discards the rows and the `pg_statistic` histograms, but not `reltuples`/`relpages`,
     * which `ANALYZE` writes in place: those keep counting the seeded rows until the next (auto)`ANALYZE`.
     *
     * @param array<string, mixed> $parameters
     * @param BoundTypes           $types
     */
    private function planFor(string $statement, array $parameters, array $types, int $backlogRows = 0): string
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();

        try {
            $this->seed($connection, $backlogRows);

            $plan = $connection->fetchOne('EXPLAIN (FORMAT JSON) ' . $statement, $parameters, $types);

            $this->assertIsString($plan);

            return $plan;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Each insert walks `g` downwards while its age shrinks with `g`, so within an insert and across the
     * inserts — oldest first — a later `uuidv7()` id always carries a later `occurred_on`, the way the
     * writer's ids track it.
     */
    private function seed(Connection $connection, int $backlogRows): void
    {
        $insert = 'INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, metadata, ip, '
            . "user_agent, occurred_on) SELECT uuidv7(), 'security', "
            . 'CASE WHEN g % :exemptEvery = 0 THEN :exempt ELSE :ordinary END, '
            . "'user', gen_random_uuid(), gen_random_uuid(), '{}', '192.0.2.1', 'seeded', "
            . 'CAST(:anchor AS TIMESTAMPTZ) - make_interval(days => :offset + (g * :spread) / :rows) '
            . 'FROM generate_series(:rows, 1, -1) AS g';
        $integers = [
            'exemptEvery' => Types::INTEGER,
            'offset' => Types::INTEGER,
            'spread' => Types::INTEGER,
            'rows' => Types::INTEGER,
        ];
        $past = ['exempt' => 'GDPR_SUBJECT_ERASED', 'anchor' => self::ANCHOR, 'offset' => 366, 'spread' => 400];

        // Past the threshold: evidence only, or — with the backlog — one evidence row in ten among ordinary ones.
        $connection->executeStatement($insert, $past + (
            0 < $backlogRows
            ? ['ordinary' => 'BANK_ACCOUNTS_VIEWED', 'exemptEvery' => 10, 'rows' => $backlogRows]
            : ['ordinary' => 'GDPR_SUBJECT_ERASED', 'exemptEvery' => 1, 'rows' => self::EXEMPT_PAST_THRESHOLD_ROWS]
        ), $integers);
        $connection->executeStatement($insert, [
            'exempt' => 'BANK_ACCOUNTS_VIEWED',
            'ordinary' => 'BANK_ACCOUNTS_VIEWED',
            'exemptEvery' => 1,
            'anchor' => self::ANCHOR,
            'offset' => 0,
            'spread' => 364,
            'rows' => self::IN_WINDOW_ROWS,
        ], $integers);
        $connection->executeStatement('ANALYZE audit_log');
    }
}
