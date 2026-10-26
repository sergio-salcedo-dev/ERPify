<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The function and the two expression indexes behind the erasure's predicate exist, with the properties the
 * index depends on. Nothing else can see them: DBAL's introspection joins `pg_attribute` on `attnum`, which is
 * 0 for an expression column, so `doctrine:schema:validate` and `schema:update --dump-sql` stay in sync with or
 * without them. The migration owns them, and this is the only check that it still creates them.
 *
 * `IMMUTABLE` is what Postgres requires of a function in an index expression; `STRICT` and `PARALLEL SAFE` are
 * what the migration declares, pinned so a rewrite that drops them is a visible diff rather than a slower plan.
 *
 * @internal
 */
#[CoversNothing]
final class EventStoreUuidIndexSchemaFunctionalTest extends KernelTestCase
{
    #[Test]
    public function theExtractorIsAnImmutableStrictParallelSafeFunctionReturningUuids(): void
    {
        $function = $this->connection()->fetchAssociative(
            'SELECT p.provolatile, p.proisstrict, p.proparallel, format_type(p.prorettype, NULL) AS returns '
            . "FROM pg_proc p WHERE p.oid = to_regprocedure('event_store_uuids(jsonb)')",
        );

        $this->assertIsArray($function, 'event_store_uuids(jsonb) does not exist');
        // IMMUTABLE ('i'), STRICT, PARALLEL SAFE ('s'), returning uuid[].
        $this->assertSame(
            ['provolatile' => 'i', 'proisstrict' => true, 'proparallel' => 's', 'returns' => 'uuid[]'],
            $function,
        );
    }

    #[Test]
    public function eachJsonColumnHasAGinIndexOverTheExtractor(): void
    {
        $indexes = $this->connection()->fetchAllKeyValue(
            "SELECT c.relname, am.amname || ' ' || pg_get_expr(i.indexprs, i.indrelid) "
            . 'FROM pg_index i '
            . 'JOIN pg_class c ON c.oid = i.indexrelid '
            . 'JOIN pg_am am ON am.oid = c.relam '
            . "WHERE i.indrelid = 'event_store'::regclass AND i.indexprs IS NOT NULL",
        );

        $this->assertSame(
            [
                'event_store_metadata_uuids_idx' => 'gin event_store_uuids(metadata)',
                'event_store_payload_uuids_idx' => 'gin event_store_uuids(payload)',
            ],
            $this->sorted($indexes),
        );
    }

    /**
     * @param array<mixed> $indexes
     *
     * @return array<mixed>
     */
    private function sorted(array $indexes): array
    {
        \ksort($indexes);

        return $indexes;
    }

    private function connection(): Connection
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
