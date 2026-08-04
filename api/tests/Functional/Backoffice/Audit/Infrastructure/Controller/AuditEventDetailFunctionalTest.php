<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proof-of-wire for the canonical audit-event detail (`GET /audit/events/{id}`) against REAL Postgres:
 * a `change` row's field-by-field diff round-trips through the DBAL by-id read, the mapper and the
 * resource responder, and the RFC 9457 pipeline distinguishes a malformed id (400 `invalid-uuid`,
 * guarded before any lookup) from a well-formed but absent one (404 `audit-event-not-found`).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class AuditEventDetailFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/audit/events';

    private const string ACTOR_ID = '11111111-1111-7111-8111-111111111111';

    private const string RESOURCE_ID = '22222222-2222-7222-8222-222222222222';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->connection()->executeStatement('TRUNCATE audit_log RESTART IDENTITY');

        $this->authenticateClient($this->client);
    }

    /**
     * @throws JsonException
     */
    public function testDetailReturnsTheFieldByFieldDiffOfAChangeRow(): void
    {
        $id = $this->seedChangeRow(['changes' => ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']]]);

        $data = $this->detail($id);

        $this->assertSame(
            [
                'id',
                'occurredOn',
                'level',
                'action',
                'actorType',
                'actorId',
                'correlationId',
                'resourceType',
                'resourceId',
                'actorErased',
                'resourceErased',
                'metadata',
            ],
            \array_keys($data),
        );
        $this->assertSame($id, $this->stringField($data, 'id'));
        $this->assertSame('change', $this->stringField($data, 'level'));
        $this->assertSame('BANK_UPDATED', $this->stringField($data, 'action'));
        $this->assertSame('Bank', $this->stringField($data, 'resourceType'));
        $this->assertArrayHasKey('actorErased', $data);
        $this->assertFalse($data['actorErased']);
        // Both axes travel: without this one the wire cannot tell an anonymisation pseudonym from a live
        // identifier, and every affordance built on `resourceId` treats it as one.
        $this->assertArrayHasKey('resourceErased', $data);
        $this->assertFalse($data['resourceErased']);
        $this->assertMicrosecondInstant('2026-03-02T10:11:12.123456+00:00', $data);

        // The diff travels under metadata.changes; JSONB drops key order, so assert the leaves.
        $change = $this->changeFor($data, 'name');
        $this->assertSame('BBVA', $change['old'] ?? null);
        $this->assertSame('BBVA S.A.', $change['new'] ?? null);
    }

    /**
     * @throws JsonException
     */
    public function testAMalformedIdIsRejectedAsFourHundredInvalidUuidBeforeAnyLookup(): void
    {
        $body = $this->get(self::ENDPOINT . '/not-a-uuid');

        self::assertResponseStatusCodeSame(400);
        $this->assertProblemJson();
        $this->assertSame('invalid-uuid', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testAWellFormedButAbsentIdIsFourHundredAndFour(): void
    {
        $body = $this->get(self::ENDPOINT . '/' . Uuid::generate());

        self::assertResponseStatusCodeSame(404);
        $this->assertProblemJson();
        $this->assertSame('audit-event-not-found', $this->type($body));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function seedChangeRow(array $changes): string
    {
        $entry = AuditLogEntry::create(
            'BANK_UPDATED',
            AuditLevel::CHANGE,
            ActorContext::forUser(self::ACTOR_ID),
            Uuid::generate(),
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            AuditResource::of('Bank', self::RESOURCE_ID),
            $changes,
        );
        (new DbalAuditLogWriter($this->connection()))->write($entry);

        return $entry->id;
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        $body = $this->get(self::ENDPOINT . '/' . $id);

        self::assertResponseIsSuccessful();
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        /** @phpstan-var array<string, mixed> */
        return $body['data'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertMicrosecondInstant(string $expectedUtc, array $data): void
    {
        $this->assertArrayHasKey('occurredOn', $data);
        $this->assertIsString($data['occurredOn']);

        $this->assertSame(
            $expectedUtc,
            (new DateTimeImmutable($data['occurredOn']))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.uP'),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function changeFor(array $data, string $field): array
    {
        $this->assertArrayHasKey('metadata', $data);
        $this->assertIsArray($data['metadata']);
        $changes = $data['metadata']['changes'] ?? null;
        $this->assertIsArray($changes);
        $change = $changes[$field] ?? null;
        $this->assertIsArray($change);

        /** @phpstan-var array<string, mixed> */
        return $change;
    }

    private function assertProblemJson(): void
    {
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        $this->assertIsString($contentType);
        $this->assertStringContainsString('application/problem+json', $contentType);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key): string
    {
        $this->assertArrayHasKey($key, $data);
        $this->assertIsString($data[$key]);

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function type(array $body): string
    {
        $this->assertArrayHasKey('type', $body);
        $this->assertIsString($body['type']);

        return $body['type'];
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);

        $decoded = \json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @phpstan-var array<string, mixed> */
        return $decoded;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
