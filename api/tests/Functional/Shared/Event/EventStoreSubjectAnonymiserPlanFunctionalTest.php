<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\SubjectPseudonym;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStoreSubjectAnonymiser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asks the planner whether the erasure's `UPDATE` still reaches `event_store` through the UUID index rather
 * than a sequential scan of the whole log. Equivalence of the rows selected is proven elsewhere; this is the
 * other half of the contract — the predicate and the indexed expression must stay the same expression, and
 * only the planner can say whether they still are.
 *
 * The statement is captured from the adapter itself, never restated here. The plan is a costed choice, so it
 * is asked over a population seeded here and `ANALYZE`d, inside a transaction that is rolled back — over the
 * ambient rows of a test database the answer would be a property of whatever happened to be there. Plain
 * `EXPLAIN`, never `ANALYZE`: the statement would rewrite rows. No `enable_seqscan = off` either, which would
 * make the planner take an index it would otherwise refuse and prove nothing.
 *
 * @internal
 */
#[CoversNothing]
final class EventStoreSubjectAnonymiserPlanFunctionalTest extends KernelTestCase
{
    private const string SUBJECT = '0190f400-0000-7000-8000-0000000000e1';

    private const string PSEUDONYM = '0190f400-0000-7000-8000-0000000000e2';

    private const array UUID_INDEXES = ['event_store_payload_uuids_idx', 'event_store_metadata_uuids_idx'];

    private const int SEEDED_ROWS = 20000;

    #[Test]
    public function theErasureReachesBothJsonColumnsThroughTheirUuidIndexesWithoutScanningTheLog(): void
    {
        $plan = $this->planFor($this->statementTheAnonymiserEmits());
        $rendered = (string) \json_encode($plan);
        $nodes = $this->nodes($plan);

        foreach (self::UUID_INDEXES as $index) {
            $this->assertContains(
                $index,
                \array_column($nodes, 'Index Name'),
                \sprintf(
                    'The erasure does not reach its rows through `%s`: the predicate differs from the indexed '
                    . 'expression, or the index does not exist. Plan: %s',
                    $index,
                    $rendered,
                ),
            );
        }

        foreach ($nodes as $node) {
            $this->assertFalse(
                'Seq Scan' === ($node['Node Type'] ?? null) && 'event_store' === ($node['Relation Name'] ?? null),
                'The erasure scans the whole of event_store sequentially. Plan: ' . $rendered,
            );
        }
    }

    private function statementTheAnonymiserEmits(): string
    {
        $captured = '';
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$captured): int {
                $captured = $sql;

                return 0;
            },
        );

        (new DbalEventStoreSubjectAnonymiser($connection))->anonymise(
            SubjectPseudonymisation::of(
                subjectId: self::SUBJECT,
                pseudonym: SubjectPseudonym::fromString(self::PSEUDONYM),
            ),
        );

        $this->assertNotSame('', $captured, 'the anonymiser emitted no statement to explain');

        return $captured;
    }

    /**
     * @return array<mixed>
     */
    private function planFor(string $statement): array
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
                . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
                . "SELECT gen_random_uuid(), gen_random_uuid(), 'Test.Plan', 1, 'erpify.test.plan', 1, "
                . "jsonb_build_object('userId', gen_random_uuid()::text, 'name', 'row ' || g), '{}', NULL, "
                . 'now(), now() FROM generate_series(1, :rows) g',
                ['rows' => self::SEEDED_ROWS],
            );
            $connection->executeStatement(
                'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
                . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
                . "SELECT gen_random_uuid(), gen_random_uuid(), 'Test.Plan', 1, 'erpify.test.plan', 1, "
                . "jsonb_build_object('userId', upper(:subject)), '{}', NULL, now(), now()",
                ['subject' => self::SUBJECT],
            );
            $connection->executeStatement('ANALYZE event_store');

            $plan = $connection->fetchOne(
                'EXPLAIN (FORMAT JSON) ' . $statement,
                ['subject_id' => self::SUBJECT, 'pseudonym' => self::PSEUDONYM],
            );
            $this->assertIsString($plan);

            $decoded = \json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);

            return $decoded;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Every plan node in the tree, flattened, so the assertions do not depend on which node shape — a Bitmap
     * Index Scan under a BitmapOr today — the planner wraps an index in.
     *
     * @param array<mixed> $tree
     *
     * @return list<array<mixed>>
     */
    private function nodes(array $tree): array
    {
        $nodes = [];

        if (isset($tree['Node Type'])) {
            $nodes[] = $tree;
        }

        foreach ($tree as $value) {
            if (\is_array($value)) {
                $nodes = [...$nodes, ...$this->nodes($value)];
            }
        }

        return $nodes;
    }
}
