<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Controller;

use DateTimeImmutable;
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
 * Proof-of-wire for the audit investigation timeline against REAL Postgres: the DBAL keyset
 * paginator + filters + {@see \Erpify\Shared\Search\Infrastructure\Http\SearchResponder} survive real
 * navigation (forward/backward, microsecond ties, gap recovery), the error contract maps filter and
 * cursor faults to the right status, and the NFR9 indexes are usable for the timeline query.
 * Navigation uses ONLY the server-composed `links` verbatim — never client-side reconstruction.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversNothing]
final class AuditTimelineSearchCursorFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/audit/timeline';

    private const int DATASET_SIZE = 30;

    /** Valid v7 UUIDs so {@see ActorContext::forUser} / {@see AuditResource} accept them. */
    private const string ACTOR_ID = '11111111-1111-7111-8111-111111111111';

    private const string RESOURCE_ID = '22222222-2222-7222-8222-222222222222';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->seedTimeline();

        $this->authenticateClient($this->client);
    }

    /**
     * @throws JsonException
     */
    public function testFirstPageIsNewestFirstWithTheConstantCursorOnlyEnvelope(): void
    {
        $body = $this->get(self::ENDPOINT . '?limit=5');

        self::assertResponseIsSuccessful();
        $pagination = $this->pagination($body);

        $this->assertSame(['hasNext', 'hasPrev', 'count', 'links'], \array_keys($pagination));
        $this->assertSame(['next', 'prev'], \array_keys($this->links($pagination)));

        // Default order is occurred_on DESC: ACTION_01 is the most recent (base − 1 min).
        $this->assertSame(['ACTION_01', 'ACTION_02', 'ACTION_03', 'ACTION_04', 'ACTION_05'], $this->actions($body));
        $this->assertTrue($this->flag($pagination, 'hasNext'));
        $this->assertFalse($this->flag($pagination, 'hasPrev'));
        $this->assertNull($this->link($pagination, 'prev'));
        $this->assertNotNull($this->link($pagination, 'next'));
        $this->assertNull($this->totalCount($pagination), 'audit timeline is light-only: count is null');
    }

    /**
     * @throws JsonException
     */
    public function testForwardThenBackwardNavigationViaLinksRoundTrips(): void
    {
        $page1 = $this->get(self::ENDPOINT . '?limit=5');
        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);

        $page2 = $this->get($next);
        self::assertResponseIsSuccessful();
        $this->assertSame(['ACTION_06', 'ACTION_07', 'ACTION_08', 'ACTION_09', 'ACTION_10'], $this->actions($page2));
        $this->assertSame([], \array_intersect($this->actions($page1), $this->actions($page2)), 'no overlap');

        $prev = $this->link($this->pagination($page2), 'prev');
        $this->assertNotNull($prev);
        $back = $this->get($prev);
        self::assertResponseIsSuccessful();
        $this->assertSame($this->actions($page1), $this->actions($back), 'prev round-trips to page 1');
    }

    /**
     * The keystone of the DBAL-over-ORM decision: `occurred_on` is `TIMESTAMPTZ(6)`, so a cursor that
     * truncated it to seconds would skip a row tied on the second. Two rows in the same second, paged
     * one at a time, must both appear in micro order.
     *
     * @throws JsonException
     */
    public function testMicrosecondTiesArePaginatedWithoutLoss(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('TRUNCATE audit_log RESTART IDENTITY');

        $writer = new DbalAuditLogWriter($connection);
        $writer->write($this->entry('TIE_LOW', new DateTimeImmutable('2026-03-01T09:00:00.100000+00:00')));
        $writer->write($this->entry('TIE_HIGH', new DateTimeImmutable('2026-03-01T09:00:00.900000+00:00')));

        $page1 = $this->get(self::ENDPOINT . '?limit=1');
        $this->assertSame(['TIE_HIGH'], $this->actions($page1), 'DESC: the larger microsecond comes first');

        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);
        $page2 = $this->get($next);
        $this->assertSame(['TIE_LOW'], $this->actions($page2), 'the sub-second row is not skipped by the cursor');
    }

    /**
     * @throws JsonException
     */
    public function testFilterByActorIdReturnsOnlyThatActor(): void
    {
        $body = $this->get(self::ENDPOINT . '?' . $this->filterQuery('actorId', 'eq', self::ACTOR_ID));

        self::assertResponseIsSuccessful();
        $this->assertSame(['ACTION_01', 'ACTION_02', 'ACTION_03'], $this->actions($body));

        foreach ($this->field($body, 'actorId') as $actorId) {
            $this->assertSame(self::ACTOR_ID, $actorId);
        }
    }

    /**
     * @throws JsonException
     */
    public function testFilterByLevelInList(): void
    {
        $body = $this->get(self::ENDPOINT . '?limit=100&' . $this->filterQuery('level', 'eq', 'security'));

        self::assertResponseIsSuccessful();
        $this->assertCount(self::DATASET_SIZE / 2, $this->actions($body));

        foreach ($this->field($body, 'level') as $level) {
            $this->assertSame('security', $level);
        }
    }

    /**
     * @throws JsonException
     */
    public function testFilterByActionContains(): void
    {
        $body = $this->get(self::ENDPOINT . '?' . $this->filterQuery('action', 'contains', 'ACTION_01'));

        self::assertResponseIsSuccessful();
        $this->assertSame(['ACTION_01'], $this->actions($body));
    }

    /**
     * @throws JsonException
     */
    public function testDateRangeFilterIsInclusiveOfTheBoundInstant(): void
    {
        // Rows are base − i minutes; ACTION_03 sits at 2026-03-01T11:57:00Z. gte that bound keeps 01..03.
        $body = $this->get(self::ENDPOINT . '?' . $this->filterQuery('occurredOn', 'gte', '2026-03-01T11:57:00+00:00'));

        self::assertResponseIsSuccessful();
        $this->assertSame(['ACTION_01', 'ACTION_02', 'ACTION_03'], $this->actions($body));
    }

    /**
     * @throws JsonException
     */
    public function testMalformedUuidFilterIsRejected(): void
    {
        $body = $this->get(self::ENDPOINT . '?' . $this->filterQuery('actorId', 'eq', 'not-a-uuid'));

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('invalid-search-value', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testUnknownFilterFieldIsRejected(): void
    {
        $body = $this->get(self::ENDPOINT . '?' . $this->filterQuery('bogus', 'eq', 'x'));

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('unknown-search-field', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testGarbageCursorIsRejectedAsInvalidCursor(): void
    {
        $body = $this->get(self::ENDPOINT . '?after=not-a-real-cursor');

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('invalid-cursor', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testAfterAndBeforeAreMutuallyExclusive(): void
    {
        $body = $this->get(self::ENDPOINT . '?after=AAAA&before=BBBB');

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('validation-failed', $this->type($body));
    }

    /**
     * A cursor binds to the query it was minted for: replaying it with a filter the original page did
     * not carry changes the fingerprint, so it is rejected rather than mixing two result sets.
     *
     * @throws JsonException
     */
    public function testCursorIsRejectedWhenTheQueryChanges(): void
    {
        $page1 = $this->get(self::ENDPOINT . '?limit=5');
        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);
        $after = $this->cursorParam($next, 'after');

        $url = self::ENDPOINT . '?limit=5&' . $this->filterQuery('level', 'eq', 'security')
            . '&after=' . \rawurlencode($after);
        $body = $this->get($url);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('invalid-cursor', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testLimitDefaultsTo25AndCeilsAt100(): void
    {
        $this->assertCount(25, $this->actions($this->get(self::ENDPOINT)), 'omitted limit defaults to 25');

        $this->get(self::ENDPOINT . '?limit=101');
        self::assertResponseStatusCodeSame(422, 'limit over the 100 ceiling is rejected');

        $this->get(self::ENDPOINT . '?limit=0');
        self::assertResponseStatusCodeSame(422, 'limit below 1 is rejected');
    }

    /**
     * NFR9: the timeline order and the actor-kind filter must be index-backed, not full scans. On a
     * tiny table Postgres legitimately prefers a seq scan, so seq scans are disabled to assert the
     * indexes are USABLE for each access pattern (not that they win at this volume).
     */
    public function testTimelineAccessPathsAreIndexBacked(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('SET enable_seqscan = off');

        try {
            $this->assertStringContainsString(
                'audit_log_timeline_idx',
                $this->explain($connection, 'SELECT id FROM audit_log ORDER BY occurred_on DESC, id DESC LIMIT 26'),
                'the global timeline order uses (occurred_on, id)',
            );
            $this->assertStringContainsString(
                'audit_log_actor_type_idx',
                $this->explain(
                    $connection,
                    "SELECT id FROM audit_log WHERE actor_type = 'user' ORDER BY occurred_on DESC, id DESC LIMIT 26",
                ),
                'filtering by actor_type uses (actor_type, occurred_on)',
            );
        } finally {
            $connection->executeStatement('SET enable_seqscan = on');
        }
    }

    private function seedTimeline(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('TRUNCATE audit_log RESTART IDENTITY');

        $writer = new DbalAuditLogWriter($connection);
        $base = new DateTimeImmutable('2026-03-01T12:00:00.000000+00:00');

        for ($i = 1; $i <= self::DATASET_SIZE; ++$i) {
            $writer->write(AuditLogEntry::create(
                \sprintf('ACTION_%02d', $i),
                0 === $i % 2 ? AuditLevel::SECURITY : AuditLevel::ACTIVITY,
                $i <= 3 ? ActorContext::forUser(self::ACTOR_ID) : ActorContext::anonymous(),
                Uuid::generate(),
                $base->modify(\sprintf('-%d minutes', $i)),
                $i <= 2 ? AuditResource::of('Bank', self::RESOURCE_ID) : null,
            ));
        }

        // `TRUNCATE` does not reset `pg_statistic`, so without this the planner chooses for the table as it was
        // BEFORE the seed — whatever the rest of the suite happened to leave behind. The index-backed access
        // assertion then passes or fails on the previous test's data rather than on this one's, which is how a
        // deterministic claim about access paths turns into a run-order lottery.
        $connection->executeStatement('ANALYZE audit_log');
    }

    private function entry(string $action, DateTimeImmutable $occurredOn): AuditLogEntry
    {
        return AuditLogEntry::create(
            $action,
            AuditLevel::ACTIVITY,
            ActorContext::anonymous(),
            Uuid::generate(),
            $occurredOn,
        );
    }

    private function filterQuery(string $field, string $operator, string $value): string
    {
        return \http_build_query(['filters' => [['field' => $field, 'operator' => $operator, 'value' => $value]]]);
    }

    private function explain(Connection $connection, string $sql): string
    {
        $lines = [];

        foreach ($connection->fetchAllAssociative('EXPLAIN ' . $sql) as $row) {
            foreach ($row as $cell) {
                $this->assertIsString($cell);
                $lines[] = $cell;
            }
        }

        return \implode("\n", $lines);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
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

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function pagination(array $body): array
    {
        $this->assertArrayHasKey('pagination', $body);
        $this->assertIsArray($body['pagination']);

        /** @phpstan-var array<string, mixed> */
        return $body['pagination'];
    }

    /**
     * @param array<string, mixed> $pagination
     *
     * @return array<string, mixed>
     */
    private function links(array $pagination): array
    {
        $this->assertArrayHasKey('links', $pagination);
        $this->assertIsArray($pagination['links']);

        /** @phpstan-var array<string, mixed> */
        return $pagination['links'];
    }

    /**
     * @param array<string, mixed> $pagination
     */
    private function flag(array $pagination, string $key): bool
    {
        $this->assertArrayHasKey($key, $pagination);
        $this->assertIsBool($pagination[$key]);

        return $pagination[$key];
    }

    /**
     * @param array<string, mixed> $pagination
     */
    private function totalCount(array $pagination): ?int
    {
        $this->assertArrayHasKey('count', $pagination);
        $value = $pagination['count'];
        $this->assertTrue(null === $value || \is_int($value));

        /** @var int|null $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $pagination
     */
    private function link(array $pagination, string $which): ?string
    {
        $links = $this->links($pagination);
        $this->assertArrayHasKey($which, $links);
        $value = $links[$which];
        $this->assertTrue(null === $value || \is_string($value));

        /** @var string|null $value */
        return $value;
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
     * @param array<string, mixed> $body
     *
     * @return list<string|null>
     */
    private function actions(array $body): array
    {
        return $this->field($body, 'action');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string|null>
     */
    private function field(array $body, string $key): array
    {
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $values = [];

        foreach ($body['data'] as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey($key, $item);
            $cell = $item[$key];

            if (null !== $cell) {
                $this->assertIsString($cell);
            }

            $values[] = $cell;
        }

        return $values;
    }

    private function cursorParam(string $relativeUrl, string $param): string
    {
        $query = \parse_url($relativeUrl, PHP_URL_QUERY);
        $this->assertIsString($query);
        \parse_str($query, $params);
        $this->assertArrayHasKey($param, $params);
        $this->assertIsString($params[$param]);

        return $params[$param];
    }
}
