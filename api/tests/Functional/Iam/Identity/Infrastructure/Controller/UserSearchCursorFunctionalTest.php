<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Proof-of-wire for the cursor-only register list against REAL Postgres: `Page<UserRow>` + the
 * {@see \Erpify\Shared\Search\Infrastructure\Http\SearchResponder} + the keyset engine survive real
 * navigation, not just compilation. Navigation uses ONLY the server-composed `links.next` / `links.prev`
 * verbatim — never client-side query reconstruction. Runs as an ADMIN (the only tier the opt-out register
 * admits) and filters to a seeded `cursor-*` subset so the walk is deterministic amid the suite's other
 * identity rows.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class UserSearchCursorFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/users';

    private const string FIRST_PAGE_QUERY
        = '?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=cursor-'
        . '&sort=email&direction=ASC&limit=2';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Idempotent isolation: drop any prior seeded subset (unique emails) before re-seeding. Surgical
        // rather than a table truncate, so the suite's other identity rows and sessions stay intact.
        $this->entityManager()->getConnection()->executeStatement(
            "DELETE FROM identity_user WHERE email LIKE 'cursor-%@erpify.test'",
        );

        // Three users with ordered emails so `sort=email ASC` is a deterministic cursor-1 → cursor-3 walk.
        foreach (['cursor-1', 'cursor-2', 'cursor-3'] as $label) {
            $this->entityManager()->persist(UserFixtureFactory::create(
                Uuid::v7()->toRfc4122(),
                $label . '@erpify.test',
                'seed-password',
                [Role::VIEWER->value],
            ));
        }

        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $this->authenticateAdminClient($this->client);
    }

    /**
     * @throws JsonException
     */
    public function testFirstPageEmitsTheCursorOnlyEnvelopeWithAForwardOnlyAffordance(): void
    {
        $body = $this->get(self::ENDPOINT . self::FIRST_PAGE_QUERY);

        self::assertResponseIsSuccessful();
        $pagination = $this->pagination($body);

        $this->assertSame(['hasNext', 'hasPrev', 'count', 'links'], \array_keys($pagination));
        $this->assertSame(['next', 'prev'], \array_keys($this->links($pagination)));
        $this->assertTrue($this->flag($pagination, 'hasNext'));
        $this->assertFalse($this->flag($pagination, 'hasPrev'));
        $this->assertNotNull($this->link($pagination, 'next'));
        $this->assertNull($this->link($pagination, 'prev'));
        $this->assertSame(['cursor-1@erpify.test', 'cursor-2@erpify.test'], $this->emails($body));
    }

    /**
     * @throws JsonException
     */
    public function testForwardThenBackwardNavigationViaLinksRoundTrips(): void
    {
        $page1 = $this->get(self::ENDPOINT . self::FIRST_PAGE_QUERY);
        $next = $this->link($this->pagination($page1), 'next');
        $this->assertNotNull($next);

        // Navigate the server link VERBATIM (no client reconstruction).
        $page2 = $this->get($next);
        self::assertResponseIsSuccessful();
        $page2Pagination = $this->pagination($page2);

        $this->assertSame(['cursor-3@erpify.test'], $this->emails($page2));
        $this->assertFalse($this->flag($page2Pagination, 'hasNext'));
        $this->assertTrue($this->flag($page2Pagination, 'hasPrev'));
        $this->assertSame([], \array_intersect($this->emails($page1), $this->emails($page2)), 'no overlap');

        $prev = $this->link($page2Pagination, 'prev');
        $this->assertNotNull($prev);

        $back = $this->get($prev);
        self::assertResponseIsSuccessful();
        $this->assertSame($this->emails($page1), $this->emails($back), 'prev round-trips to page 1');
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
     *
     * @return list<string>
     */
    private function emails(array $body): array
    {
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $emails = [];

        foreach ($body['data'] as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('email', $item);
            $this->assertIsString($item['email']);
            $emails[] = $item['email'];
        }

        return $emails;
    }
}
