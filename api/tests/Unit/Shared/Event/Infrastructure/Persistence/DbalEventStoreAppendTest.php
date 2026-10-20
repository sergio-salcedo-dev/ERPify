<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two decisions {@see DbalEventStore::append()} makes about what it writes and what it refuses to
 * promise. `payload` and `metadata` are each written as a JSON object whatever the envelope holds — an empty
 * PHP array encodes as `[]`, which would store a JSON array in a column every reader treats as an object. And
 * a stream UNIQUE violation propagates untranslated: `aggregate_version` is informative, so no retryable
 * answer is invented.
 *
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreAppendTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('provideWritesBothJsonColumnsAsAJsonObjectCases')]
    public function testWritesBothJsonColumnsAsAJsonObject(array $data, string $expected): void
    {
        $captured = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->anything(), $this->callback(static function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            }))
            ->willReturn(1)
        ;

        $store = new DbalEventStore($connection, $this->serializerReturning($data, $data));
        $store->append($this->anEvent());

        $this->assertSame($expected, $captured['payload'] ?? null, 'payload');
        $this->assertSame($expected, $captured['metadata'] ?? null, 'metadata');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideWritesBothJsonColumnsAsAJsonObjectCases(): iterable
    {
        yield 'empty is an empty object' => [[], '{}'];
        yield 'keyed is an object' => [['correlation_id' => 'x'], '{"correlation_id":"x"}'];
        yield 'the coercion is top-level only' => [['x' => []], '{"x":[]}'];
    }

    public function testAStreamUniqueViolationPropagatesUntranslated(): void
    {
        $violation = $this->createStub(UniqueConstraintViolationException::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException($violation);

        $store = new DbalEventStore($connection, $this->serializerReturning([], []));

        $this->expectExceptionObject($violation);

        $store->append($this->anEvent());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    private function serializerReturning(array $payload, array $metadata): DomainEventSerializer
    {
        $serializer = $this->createStub(DomainEventSerializer::class);
        $serializer->method('serialize')->willReturn(['payload' => $payload, 'metadata' => $metadata]);

        return $serializer;
    }

    private function anEvent(): BankCreatedDomainEvent
    {
        $now = '2026-01-01T00:00:00+00:00';

        return new BankCreatedDomainEvent(Uuid::generate(), new BankSnapshot('Bank', 'BANK', $now, $now));
    }
}
