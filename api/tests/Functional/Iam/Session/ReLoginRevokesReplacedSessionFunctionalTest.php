<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A login over a browser that already holds a live registry session retires that session instead of
 * orphaning it — driven through the real `json_login` firewall, because what is under test is the hand-off
 * between Symfony's own login listeners (the anti-fixation `migrate()` among them) and the minting listener:
 * the replaced correlation has to still be in the bag when minting reads it, and no unit test reaches that seam.
 *
 * The oracle is the registry as the owner reads it — "my sessions" over HTTP — and the table itself, never the
 * use case's return: a ghost device is a row still `ACTIVE` that no browser can present, so only the rows say
 * whether one was left. The third case is the radius control: two browsers of one identity are two devices,
 * and a login on the second must leave the first alone.
 *
 * @internal
 */
#[CoversClass(StartSession::class)]
#[CoversClass(SessionMintingSuccessListener::class)]
final class ReLoginRevokesReplacedSessionFunctionalTest extends WebTestCase
{
    use ResolvesContainerServices;
    use SeedsAnOrganizationMember;

    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string MY_SESSIONS_PATH = '/api/v1/sessions';

    private const string ORIGIN = 'http://localhost';

    private const string PASSWORD = 'the-password-of-a-re-login';

    private const string REVOKED_EVENT = 'erpify.iam.session.revoked';

    private KernelBrowser $client;

    private Connection $connection;

    /** @var array<string, string> email by user id, for the teardown */
    private array $members = [];

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->members as $userId => $email) {
            $this->connection->executeStatement(
                'DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)',
                ['id' => $userId],
            );
            $this->forgetTheOrganizationMember($userId, $email);
        }

        $this->members = [];

        parent::tearDown();
    }

    public function testASecondLoginOnTheSameBrowserRevokesTheFirstAndLeavesNoGhostDevice(): void
    {
        [$userId, $email] = $this->seedMember('relogin');

        $this->login($email);
        $first = $this->soleActiveSession($userId);

        $this->login($email);
        $second = $this->soleActiveSession($userId);

        $this->assertNotSame($first, $second, 'the second login minted a session of its own');
        $this->assertSame('REVOKED', $this->statusOf($first));
        $this->assertNotNull($this->revokedAtOf($first), 'revoked through the aggregate, which stamps the instant');
        $this->assertSame(1, $this->revokedEventCount($first), 'the single-revocation fact, once');

        $listed = $this->mySessions();
        $this->assertCount(1, $listed, 'my sessions shows exactly the device in hand');
        $this->assertSame($second, $listed[0]['id'] ?? null);
        $this->assertTrue($listed[0]['current'] ?? null);
    }

    /**
     * Two people on one browser. The first person's row is unreachable once the second signs in over it — the
     * bag that named it now names the second person's session — so leaving it `ACTIVE` would show its owner a
     * device they can no longer use.
     */
    public function testASignInOverAnotherIdentitysSessionRevokesThatSession(): void
    {
        [$firstUserId, $firstEmail] = $this->seedMember('first');
        [$secondUserId, $secondEmail] = $this->seedMember('second');

        $this->login($firstEmail);
        $replaced = $this->soleActiveSession($firstUserId);

        $this->login($secondEmail);
        $minted = $this->soleActiveSession($secondUserId);

        $this->assertSame(0, $this->activeSessionCount($firstUserId));
        $this->assertSame('REVOKED', $this->statusOf($replaced));
        $this->assertSame(1, $this->revokedEventCount($replaced));

        $listed = $this->mySessions();
        $this->assertCount(1, $listed);
        $this->assertSame($minted, $listed[0]['id'] ?? null);
    }

    /**
     * The radius: what is revoked is the row THIS browser correlates, never "the identity's other sessions".
     * Without a cookie the second login carries no correlation, so the first device must survive it.
     */
    public function testALoginFromAnotherBrowserLeavesTheFirstDeviceAlone(): void
    {
        [$userId, $email] = $this->seedMember('two-browsers');

        $this->login($email);
        $first = $this->soleActiveSession($userId);

        $this->client->getCookieJar()->clear();
        $this->login($email);

        $this->assertSame(2, $this->activeSessionCount($userId));
        $this->assertSame('ACTIVE', $this->statusOf($first));
        $this->assertSame(0, $this->revokedEventCount($first));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedMember(string $label): array
    {
        $userId = Uuid::generate();
        $email = \sprintf('%s-%s@erpify.test', $label, \str_replace('-', '', $userId));

        $this->seedAnOrganizationMember($userId, $email, self::PASSWORD);
        $this->members[$userId] = $email;

        return [$userId, $email];
    }

    private function login(string $email): void
    {
        // Under a non-rebooting client the manager outlives the request, so it is cleared before each login
        // for the reason it is cleared before each read: the row, not a copy the previous request left behind.
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
            ],
            content: (string) \json_encode(['email' => $email, 'password' => self::PASSWORD]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function mySessions(): array
    {
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_GET,
            self::MY_SESSIONS_PATH,
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertTrue(\array_is_list($body['data']));

        $sessions = [];

        foreach ($body['data'] as $session) {
            $this->assertIsArray($session);
            $sessions[] = $session;
        }

        return $sessions;
    }

    private function soleActiveSession(string $userId): string
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = 'ACTIVE'",
            ['id' => $userId],
        );
        $this->assertCount(1, $ids, 'exactly one live session for the identity');
        $this->assertIsString($ids[0]);

        return $ids[0];
    }

    private function activeSessionCount(string $userId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT count(*) FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = 'ACTIVE'",
            ['id' => $userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function statusOf(string $sessionId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM iam_session WHERE id = CAST(:id AS uuid)',
            ['id' => $sessionId],
        );
        $this->assertIsString($status, 'the replaced row is kept, revoked, never deleted');

        return $status;
    }

    private function revokedAtOf(string $sessionId): mixed
    {
        return $this->connection->fetchOne(
            'SELECT revoked_at FROM iam_session WHERE id = CAST(:id AS uuid)',
            ['id' => $sessionId],
        );
    }

    private function revokedEventCount(string $sessionId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM event_store WHERE aggregate_id = CAST(:id AS uuid) AND event_name = :name',
            ['id' => $sessionId, 'name' => self::REVOKED_EVENT],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
