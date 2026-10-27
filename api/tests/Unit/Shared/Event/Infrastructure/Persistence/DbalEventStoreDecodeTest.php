<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `payload` and `metadata` are JSON objects in the store, so {@see DbalEventStore::stream()} refuses any other
 * root as a corrupt row instead of reading it as an empty body — which the payload-less events would accept
 * without ever noticing.
 *
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreDecodeTest extends TestCase
{
    public function testReadsAnEmptyAndAKeyedObject(): void
    {
        $event = $this->streamOne('{"bankId":"x"}', '{}');

        $this->assertSame(['bankId' => 'x'], $event->payload);
        $this->assertSame([], $event->metadata);
    }

    public function testReadsTheEmptyListRowsWrittenBeforeTheObjectCoercionAsAnEmptyBody(): void
    {
        $event = $this->streamOne('[]', '[]');

        $this->assertSame([], $event->payload);
        $this->assertSame([], $event->metadata);
    }

    #[DataProvider('provideRefusesAnyRootThatIsNotAnObjectCases')]
    public function testRefusesAnyRootThatIsNotAnObject(mixed $stored): void
    {
        foreach (['payload', 'metadata'] as $column) {
            $payload = 'payload' === $column ? $stored : '{}';
            $metadata = 'metadata' === $column ? $stored : '{}';

            try {
                $this->streamOne($payload, $metadata);
                $this->fail(\sprintf('A non-object %s was read as an empty body.', $column));
            } catch (CorruptEventStoreRow $e) {
                $this->assertSame(['column' => $column, 'sequence' => 1], $e->context());
            }
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRefusesAnyRootThatIsNotAnObjectCases(): iterable
    {
        yield 'a string' => ['"x"'];
        yield 'a number' => ['5'];
        yield 'a JSON null' => ['null'];
        yield 'a boolean' => ['true'];
        yield 'a list' => ['[1,2]'];
        yield 'an SQL null' => [null];
        yield 'a non-string value' => [5];
    }

    private function streamOne(mixed $payload, mixed $metadata): StoredEvent
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'sequence' => '1',
            'event_id' => '0190a000-0000-7000-8000-000000000001',
            'aggregate_id' => '0190a000-0000-7000-8000-000000000002',
            'aggregate_type' => 'bank',
            'aggregate_version' => '1',
            'event_name' => 'erpify.backoffice.bank.deleted',
            'event_version' => '1',
            'payload' => $payload,
            'metadata' => $metadata,
            'tenant_id' => null,
            'occurred_on' => '2026-01-01 00:00:00+00',
            'recorded_on' => '2026-01-01 00:00:00+00',
        ]]);

        $store = new DbalEventStore($connection, $this->createStub(DomainEventSerializer::class));

        foreach ($store->stream(0, ['erpify.backoffice.bank.deleted'], 10) as $event) {
            return $event;
        }

        $this->fail('The stream yielded no event.');
    }
}
