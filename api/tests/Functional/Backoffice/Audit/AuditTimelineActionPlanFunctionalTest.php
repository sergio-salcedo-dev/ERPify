<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asks the planner whether the timeline filtered by `action` reads `audit_log_action_idx` instead of walking the
 * whole timeline and discarding rows.
 *
 * The walk is the planner's choice whenever the index is missing, and it is a fine one for a frequent action, which
 * is why nothing behavioural notices it going: the page is right either way. It only costs for a RARE action, where
 * the walk discards every other row to find its 26 — measured over 1.5M rows, 866k rows read and 201 ms against
 * 0.1 ms with the index, growing with the table. So the population here is shaped the way that matters: one
 * frequent action and a few old rows of a rare one, `ANALYZE`d, because a plan asserted over ambient data is a
 * property of the data. The rows live in a transaction that is rolled back.
 *
 * The statements restate the shape the keyset paginator emits for an equality filter (`WHERE action = :a ORDER BY
 * occurred_on DESC, id DESC LIMIT 26`) and for a contains one (`LIKE :pattern`); they do not run the paginator.
 *
 * @internal
 */
#[CoversNothing]
final class AuditTimelineActionPlanFunctionalTest extends KernelTestCase
{
    private const int FREQUENT_ROWS = 30000;

    private const int RARE_ROWS = 40;

    #[Test]
    public function aRareActionIsServedFromTheActionIndexAndNeverByWalkingTheTimeline(): void
    {
        $plan = $this->planForRareAction();

        $this->assertStringContainsString(
            'audit_log_action_idx',
            $plan,
            'The timeline filtered by action no longer reads its index, so a rare action walks the whole timeline '
            . 'and discards every other row. Plan: ' . $plan,
        );
        $this->assertStringNotContainsString(
            '"Seq Scan"',
            $plan,
            'The timeline filtered by action reads `audit_log` sequentially. Plan: ' . $plan,
        );
    }

    #[Test]
    public function aContainsFilterOnAnActionIsServedFromTheTrigramIndex(): void
    {
        $plan = $this->planFor(
            'EXPLAIN (FORMAT JSON) SELECT a.id, a.occurred_on FROM audit_log a WHERE a.action LIKE :pattern '
            . 'ORDER BY a.occurred_on DESC, a.id DESC LIMIT 26',
            ['pattern' => '%PLAN_TEST_RARE%'],
            settings: ['SET LOCAL enable_seqscan = off'],
        );

        $this->assertStringContainsString(
            'idx_audit_log_action_trgm',
            $plan,
            'A contains filter on action no longer reads its trigram index, so it scans the whole table, and so '
            . 'does a typo that matches nothing. Plan: ' . $plan,
        );
        $this->assertStringNotContainsString('"Seq Scan"', $plan, 'Plan: ' . $plan);
    }

    private function planForRareAction(): string
    {
        return $this->planFor(
            'EXPLAIN (FORMAT JSON) SELECT a.id, a.occurred_on FROM audit_log a WHERE a.action = :action '
            . 'ORDER BY a.occurred_on DESC, a.id DESC LIMIT 26',
            ['action' => 'PLAN_TEST_RARE_ACTION'],
        );
    }

    /**
     * `$settings` are session statements run before the plan is read. The contains statement uses one to rule out a
     * sequential scan: its index only pays over a table far larger than a test can seed, and a scan of 30k rows is
     * rightly the cheaper plan there. What it asks instead is whether the index CAN serve the operator (right column,
     * right operator class), which is what disappears when the index is removed or mis-declared.
     *
     * @param array<string, string> $parameters
     * @param list<string>          $settings
     */
    private function planFor(string $statement, array $parameters, array $settings = []): string
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();

        try {
            $this->seed($connection);

            foreach ($settings as $setting) {
                $connection->executeStatement($setting);
            }

            $plan = $connection->fetchOne($statement, $parameters);
            $this->assertIsString($plan);

            return $plan;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function seed(Connection $connection): void
    {
        $columns = 'INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, metadata, '
            . "occurred_on) SELECT gen_random_uuid(), 'activity', ";

        // One action every row shares, spread over two years: the walk finds its 26 almost at once for it.
        $connection->executeStatement(
            $columns . "'PLAN_TEST_FREQUENT_ACTION', 'user', gen_random_uuid(), gen_random_uuid(), '{}'::jsonb, "
            . "now() - (g * interval '30 seconds') FROM generate_series(1, " . self::FREQUENT_ROWS . ') g',
        );
        // A rare one, all of it older than the frequent rows: reaching it by the timeline means reading them all.
        $connection->executeStatement(
            $columns . "'PLAN_TEST_RARE_ACTION', 'user', gen_random_uuid(), gen_random_uuid(), '{}'::jsonb, "
            . "now() - interval '3 years' - (g * interval '1 day') FROM generate_series(1, " . self::RARE_ROWS . ') g',
        );
        $connection->executeStatement('ANALYZE audit_log');
    }
}
