<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Proof-of-wire for the PR3 cursor-only flip against REAL Postgres: it asserts that
 * `Page<Bank>` + {@see \Erpify\Shared\Infrastructure\Http\Responder\SearchResponder} + the keyset
 * engine survive real navigation, not just compilation. Navigation uses ONLY the server-composed
 * `links.next`/`links.prev` (verbatim) + the opaque cursor — never client-side query reconstruction
 * (W2/W9). `SearchResponder` is the sole envelope compositor.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class BankSearchCursorFunctionalTest extends WebTestCase
{
    private const string ENDPOINT = '/api/v1/backoffice/banks';

    private const int DATASET_SIZE = 30;

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = $this->entityManager();

        // Isolation: no DAMA rollback in this suite, so start from a known-empty table.
        $entityManager->getConnection()->executeStatement('TRUNCATE bank_account, bank RESTART IDENTITY CASCADE');

        // 30 banks with zero-padded names so `sort=name` is a deterministic 01..30 sequence.
        for ($i = 1; $i <= self::DATASET_SIZE; ++$i) {
            $label = \str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $entityManager->persist(Bank::create(Uuid::v7()->toRfc4122(), 'Bank ' . $label, 'BNK' . $label));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @throws JsonException
     */
    public function testFirstPageEmitsTheConstantCursorOnlyEnvelopeWithoutPageNumbers(): void
    {
        $body = $this->get(self::ENDPOINT . '?limit=10&sort=name&direction=ASC');

        self::assertResponseIsSuccessful();
        $pagination = $this->pagination($body);

        // Exact key set — the legacy page-number keys are gone (FR6 constant shape).
        $this->assertSame(['hasNext', 'hasPrev', 'count', 'links'], \array_keys($pagination));
        $this->assertSame(['next', 'prev'], \array_keys($this->links($pagination)));

        foreach (['currentPage', 'pageCount', 'hasMorePages', 'cursor'] as $deadKey) {
            $this->assertArrayNotHasKey($deadKey, $pagination, \sprintf('legacy key %s must be gone', $deadKey));
        }

        $this->assertTrue($this->flag($pagination, 'hasNext'));
        $this->assertFalse($this->flag($pagination, 'hasPrev'));
        $this->assertNull($this->link($pagination, 'prev'), 'first page: prev does not apply → null');
        $this->assertNotNull($this->link($pagination, 'next'));
        $this->assertNull($this->totalCount($pagination), 'light mode (default): count is null, emitted explicitly');
        $this->assertSame(['Bank 01', 'Bank 02', 'Bank 03'], \array_slice($this->names($body), 0, 3));
        $this->assertCount(10, $this->names($body));
    }

    /**
     * @throws JsonException
     */
    public function testForwardThenBackwardNavigationViaLinksRoundTrips(): void
    {
        $page1 = $this->get(self::ENDPOINT . '?limit=10&sort=name&direction=ASC');
        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);

        // Navigate the server link VERBATIM (no client reconstruction).
        $page2 = $this->get($next);
        self::assertResponseIsSuccessful();
        $page2Pagination = $this->pagination($page2);

        $this->assertSame(['Bank 11', 'Bank 12'], \array_slice($this->names($page2), 0, 2));
        $this->assertTrue($this->flag($page2Pagination, 'hasPrev'), 'page 2 can go back');
        $prev = $this->link($page2Pagination, 'prev');
        $this->assertNotNull($prev);
        $this->assertSame([], \array_intersect($this->names($page1), $this->names($page2)), 'no overlap');

        // Backward via prev returns to page 1's window.
        $back = $this->get($prev);
        self::assertResponseIsSuccessful();
        $this->assertSame($this->names($page1), $this->names($back), 'prev round-trips to page 1');
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
     * @throws JsonException
     */
    public function testGarbageCursorIsRejectedAsInvalidCursor(): void
    {
        $body = $this->get(self::ENDPOINT . '?after=not-a-real-cursor&sort=name');

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('invalid-cursor', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testCursorReplayedInTheWrongDirectionIsRejected(): void
    {
        // A `next` cursor carries dir=after; replaying it as `before` contradicts the wire param.
        $firstPage = $this->get(self::ENDPOINT . '?limit=10&sort=name&direction=ASC');
        $next = $this->link($this->pagination($firstPage), 'next');
        $this->assertNotNull($next);
        $afterCursor = $this->cursorParam($next, 'after');

        $body = $this->get(self::ENDPOINT . '?limit=10&sort=name&direction=ASC&before=' . \rawurlencode($afterCursor));

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('invalid-cursor', $this->type($body));
    }

    /**
     * @throws JsonException
     */
    public function testLimitDefaultsTo25AndCeilsAt100(): void
    {
        $this->assertCount(25, $this->names($this->get(self::ENDPOINT . '?sort=name')), 'omitted limit defaults to 25');

        $this->get(self::ENDPOINT . '?limit=101');
        self::assertResponseStatusCodeSame(422, 'limit over the 100 ceiling is rejected');

        $this->get(self::ENDPOINT . '?limit=100');
        self::assertResponseIsSuccessful();

        $this->get(self::ENDPOINT . '?limit=0');
        self::assertResponseStatusCodeSame(422, 'limit below 1 is rejected');
    }

    /**
     * @throws JsonException
     */
    public function testDetailedModePopulatesCountWhileLightLeavesItNull(): void
    {
        $detailed = $this->get(self::ENDPOINT . '?limit=10&paginationMode=detailed');
        $this->assertSame(self::DATASET_SIZE, $this->totalCount($this->pagination($detailed)));

        $light = $this->get(self::ENDPOINT . '?limit=10&paginationMode=light');
        $this->assertNull($this->totalCount($this->pagination($light)));
    }

    /**
     * @throws JsonException
     */
    public function testBeforeIntoADeletedGapIsForwardRecoverable(): void
    {
        // Reach page 2 (banks 11–20) and grab its prev cursor (points before bank 11).
        $page1 = $this->get(self::ENDPOINT . '?limit=10&sort=name&direction=ASC');
        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);
        $page2 = $this->get($next);
        $prev = $this->link($this->pagination($page2), 'prev');
        $this->assertNotNull($prev);

        // Delete banks 01–10 so navigating that prev cursor lands in a logical gap (AR6/FR3).
        $this->entityManager()->getConnection()->executeStatement(
            "DELETE FROM bank WHERE short_name LIKE 'BNK0%' OR short_name = 'BNK10'",
        );

        $gap = $this->get($prev);

        self::assertResponseIsSuccessful();
        $pagination = $this->pagination($gap);
        $this->assertSame([], $this->names($gap), 'before into a gap → empty items, never an error');
        $this->assertTrue($this->flag($pagination, 'hasNext'), 'empty-before is forward-recoverable');
        $this->assertFalse($this->flag($pagination, 'hasPrev'), 'nothing remains before the gap');
        $this->assertNotNull($this->link($pagination, 'next'), 'W10: hasNext ⇒ links.next present even when empty');
        $this->assertNull($this->link($pagination, 'prev'));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);

        $decoded = \json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
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

        /** @var array<string, mixed> $pagination */
        $pagination = $body['pagination'];

        return $pagination;
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

        /** @var array<string, mixed> $links */
        $links = $pagination['links'];

        return $links;
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
     * @return list<string>
     */
    private function names(array $body): array
    {
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $names = [];

        foreach ($body['data'] as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('name', $item);
            $this->assertIsString($item['name']);
            $names[] = $item['name'];
        }

        return $names;
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
