<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `payload` and `metadata` each have one JSON shape, an object. PHP's empty array encodes as `[]`, which would
 * give a column two shapes, and `jsonb_each('[]')` raises where `->>'key'` on `{}` merely answers NULL. The functional
 * half — what Postgres actually stores — is `DbalEventStoreStreamTest`.
 *
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreEnvelopeEncodingTest extends TestCase
{
    public function testAnEmptyEnvelopeIsEncodedAsAJsonObject(): void
    {
        $this->assertSame('{}', $this->metadataWrittenFor([]));
    }

    public function testANonEmptyEnvelopeKeepsItsKeys(): void
    {
        $this->assertSame(
            '{"correlationId":"c-1","causationId":"e-0"}',
            $this->metadataWrittenFor(['correlationId' => 'c-1', 'causationId' => 'e-0']),
        );
    }

    /**
     * Only the top level is coerced: `JSON_FORCE_OBJECT` would also rewrite a nested list into
     * `{"0": …}` and change what the envelope says.
     */
    public function testANestedListStaysAJsonArray(): void
    {
        $this->assertSame(
            '{"roles":["a","b"],"none":[]}',
            $this->metadataWrittenFor(['roles' => ['a', 'b'], 'none' => []]),
        );
    }

    public function testAnEmptyPayloadIsEncodedAsAJsonObject(): void
    {
        $this->assertSame('{}', $this->parametersWrittenFor([], [])['payload']);
    }

    public function testThePayloadIsEncodedUnchanged(): void
    {
        $this->assertSame('{"name":"Bank"}', $this->parametersWrittenFor(['name' => 'Bank'], [])['payload']);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataWrittenFor(array $metadata): string
    {
        return $this->parametersWrittenFor([], $metadata)['metadata'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     *
     * @return array{payload: string, metadata: string}
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the stub's callback mirrors `executeStatement()`'s
     * signature, and only the bound parameters are under test
     */
    private function parametersWrittenFor(array $payload, array $metadata): array
    {
        /** @var array<string, mixed> $written */
        $written = [];

        // A stub rather than a mock: the assertion is about the parameters the subject produced, never
        // about call counts on a doubled collaborator.
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                /** @param array<string, mixed> $params */
                static function (string $sql, array $params) use (&$written): int {
                    $written = $params;

                    return 1;
                },
            )
        ;

        $serializer = $this->createStub(DomainEventSerializer::class);
        $serializer->method('serialize')->willReturn(['payload' => $payload, 'metadata' => $metadata]);

        (new DbalEventStore($connection, $serializer))->append($this->anEvent());

        $writtenPayload = $written['payload'] ?? null;
        $writtenMetadata = $written['metadata'] ?? null;
        $this->assertIsString($writtenPayload);
        $this->assertIsString($writtenMetadata);

        return ['payload' => $writtenPayload, 'metadata' => $writtenMetadata];
    }

    private function anEvent(): BankCreatedDomainEvent
    {
        $now = '2026-01-01T00:00:00+00:00';

        return new BankCreatedDomainEvent(Uuid::generate(), new BankSnapshot('Bank', 'BANK', $now, $now));
    }
}
