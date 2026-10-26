<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database refuses a `payload` or `metadata` that is not a JSON object, so a write that bypasses
 * `DbalEventStore::append()` cannot leave a row the reader would call corrupt. DBAL does not introspect CHECK
 * constraints, so the migration owns them and this is the only check that it still creates them.
 *
 * @internal
 */
#[CoversNothing]
final class EventStoreJsonObjectConstraintFunctionalTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('provideRefusesAJsonRootThatIsNotAnObjectCases')]
    public function refusesAJsonRootThatIsNotAnObject(string $column, string $json): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $payload = 'payload' === $column ? $json : '{}';
            $metadata = 'metadata' === $column ? $json : '{}';
            $this->insert($connection, $payload, $metadata);
            $this->fail(\sprintf('The database stored %s as %s.', $column, $json));
        } catch (DriverException $driverException) {
            // DBAL maps no exception class to SQLSTATE 23514 on PostgreSQL, so the constraint is named instead.
            $constraint = \sprintf('event_store_%s_is_object', $column);
            $this->assertStringContainsString($constraint, $driverException->getMessage());
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusesAJsonRootThatIsNotAnObjectCases(): iterable
    {
        foreach (['payload', 'metadata'] as $column) {
            foreach (['"x"', '5', 'null', 'true', '[1]', '[[]]'] as $json) {
                yield $column . ' ' . $json => [$column, $json];
            }
        }
    }

    #[Test]
    public function acceptsAnObjectOrTheLegacyEmptyListInBothColumns(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->assertSame(1, $this->insert($connection, '{}', '{"a":[]}'));
            // Rows from before the writer's object coercion hold an empty list (ADR D4: accepted, no backfill).
            $this->assertSame(1, $this->insert($connection, '[]', '[]'));
        } finally {
            $connection->rollBack();
        }
    }

    private function insert(Connection $connection, string $payload, string $metadata): int|string
    {
        return $connection->executeStatement(
            'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
            . 'payload, metadata, occurred_on, recorded_on) '
            . "VALUES (gen_random_uuid(), gen_random_uuid(), 'probe', 1, 'probe.event', "
            . 'CAST(:payload AS JSONB), CAST(:metadata AS JSONB), now(), now())',
            ['payload' => $payload, 'metadata' => $metadata],
        );
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
