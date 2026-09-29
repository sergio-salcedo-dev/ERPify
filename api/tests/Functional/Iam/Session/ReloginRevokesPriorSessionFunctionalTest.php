<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
 * A second login from the same browser retires the session the cookie already correlated, driven through the
 * real `json_login` firewall and read back through "my sessions" — because what the defect left behind was
 * visible exactly there: the firewall migrates the native session and keeps its attributes, so the earlier
 * registry row stayed `ACTIVE`, reachable by no cookie, and listed as a second device for the rest of its window.
 *
 * The negative control is a login from another browser (a fresh cookie jar): the two cookies correlate two
 * different rows and neither login may touch the other's — the fix revokes the row THIS cookie pointed at, and
 * nothing of the identity beyond it.
 *
 * @internal
 */
#[CoversClass(StartSession::class)]
final class ReloginRevokesPriorSessionFunctionalTest extends WebTestCase
{
    use ResolvesContainerServices;
    use SeedsAnOrganizationMember;

    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string SESSIONS_PATH = '/api/v1/sessions';

    private const string ORIGIN = 'http://localhost';

    private const string PASSWORD = 'the-password-of-a-twice-logged-in-member';

    /** Name of the trigger and of its function, dropped in teardown whether or not a test created them. */
    private const string FAULT = 'relogin_revokes_prior_session_fault';

    private KernelBrowser $client;

    private Connection $connection;

    private string $userId = '';

    private string $email = '';

    private string $otherUserId = '';

    private string $otherEmail = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $this->userId = Uuid::generate();
        $this->email = \sprintf('relogin-%s@erpify.test', \str_replace('-', '', $this->userId));
        $this->seedAnOrganizationMember($this->userId, $this->email, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(\sprintf('DROP TRIGGER IF EXISTS %s ON iam_session', self::FAULT));
        $this->connection->executeStatement(\sprintf('DROP FUNCTION IF EXISTS %s()', self::FAULT));

        if ('' !== $this->userId) {
            $this->connection->executeStatement(
                'DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)',
                ['id' => $this->userId],
            );
            $this->forgetTheOrganizationMember($this->userId, $this->email);
        }

        if ('' !== $this->otherUserId) {
            $this->connection->executeStatement(
                'DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)',
                ['id' => $this->otherUserId],
            );
            $this->forgetTheOrganizationMember($this->otherUserId, $this->otherEmail);
        }

        parent::tearDown();
    }

    public function testASecondLoginFromTheSameBrowserRevokesTheSessionItsCookieCorrelated(): void
    {
        $this->login();
        $first = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(1, $first, 'the first login minted exactly one session');

        $this->login();

        $active = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(1, $active, 'the re-login left exactly one live session');
        $this->assertNotSame($first, $active, 'and it is the one the re-login minted');
        $this->assertSame($first, $this->sessionIdsIn('REVOKED'), 'the earlier row was revoked, not left behind');
        $this->assertSame(1, $this->revokedEventCount($first[0]), 'and its revocation reached the event store');

        $listed = $this->mySessions();
        $this->assertCount(1, $listed, '"my sessions" shows no phantom device');
        $this->assertSame($active[0], $listed[0]['id']);
        $this->assertTrue($listed[0]['current']);
    }

    public function testALoginFromAnotherBrowserLeavesTheFirstBrowsersSessionAlive(): void
    {
        $this->login();
        $first = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(1, $first);

        // Another browser: no cookie, so no correlation to retire.
        $this->client->getCookieJar()->clear();
        $this->login();

        $active = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(2, $active, 'both browsers keep their session');
        $this->assertContains($first[0], $active);
        $this->assertSame([], $this->sessionIdsIn('REVOKED'));
        $this->assertSame(0, $this->revokedEventCount($first[0]));
        $this->assertCount(2, $this->mySessions());
    }

    /**
     * The revocation rides the mint's transaction, so a mint the store refuses takes the revocation down with
     * it: the login fails closed and the earlier row is left `ACTIVE`. Not live, though — the minting listener
     * invalidates the native session on that failure, so no cookie correlates the row any more and it is
     * unreachable, the same prune-bounded residual as a post-commit correlation failure. What this proves is the
     * atomicity alone. The fault is a real one, raised by Postgres on the INSERT of this identity's new row —
     * after the earlier row's UPDATE in the same unit of work — so a revocation committed on its own would
     * survive it and this test would see it.
     */
    public function testAReloginWhoseMintTheStoreRefusesLeavesTheEarlierSessionAlive(): void
    {
        $this->login();
        $first = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(1, $first);

        $this->refuseSessionInserts();
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
            ],
            content: (string) \json_encode(['email' => $this->email, 'password' => self::PASSWORD]),
        );

        $this->assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame($first, $this->sessionIdsIn('ACTIVE'), 'the revocation rolled back with the mint');
        $this->assertSame([], $this->sessionIdsIn('REVOKED'));
        $this->assertSame(0, $this->revokedEventCount($first[0]), 'and so did its event');
    }

    /**
     * Another identity logging in over the same cookie: the row that cookie correlated is revoked although it is
     * not the new identity's, because only this cookie could reach it and its correlation is being overwritten.
     */
    public function testALoginAsAnotherMemberOverTheSameCookieRevokesTheFirstMembersSession(): void
    {
        $this->otherUserId = Uuid::generate();
        $this->otherEmail = \sprintf('relogin-other-%s@erpify.test', \str_replace('-', '', $this->otherUserId));
        $this->seedAnOrganizationMember($this->otherUserId, $this->otherEmail, self::PASSWORD);

        $this->login();
        $first = $this->sessionIdsIn('ACTIVE');
        $this->assertCount(1, $first);

        $this->login($this->otherEmail);

        $this->assertSame([], $this->sessionIdsIn('ACTIVE'), 'the first member keeps no live session');
        $this->assertSame($first, $this->sessionIdsIn('REVOKED'));
        $this->assertSame(1, $this->revokedEventCount($first[0]));

        $otherActive = $this->sessionIdsIn('ACTIVE', $this->otherUserId);
        $this->assertCount(1, $otherActive, 'the second member holds exactly one live session');

        $listed = $this->mySessions();
        $this->assertCount(1, $listed, "the second member's list names only its own session");
        $this->assertSame($otherActive[0], $listed[0]['id']);
        $this->assertTrue($listed[0]['current']);
    }

    private function refuseSessionInserts(): void
    {
        $this->connection->executeStatement(
            'CREATE FUNCTION ' . self::FAULT . '() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN'
            . ' RAISE EXCEPTION $msg$session insert refused by the test$msg$; END $body$',
        );
        $this->connection->executeStatement(
            'CREATE TRIGGER ' . self::FAULT . ' BEFORE INSERT ON iam_session FOR EACH ROW'
            . " WHEN (NEW.user_id = '" . $this->userId . "'::uuid) EXECUTE FUNCTION " . self::FAULT . '()',
        );
    }

    private function login(?string $email = null): void
    {
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
            ],
            content: (string) \json_encode(['email' => $email ?? $this->email, 'password' => self::PASSWORD]),
        );

        $this->assertTrue(
            $this->client->getResponse()->isSuccessful(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * @return list<array{id: string, current: bool}>
     */
    private function mySessions(): array
    {
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_GET,
            self::SESSIONS_PATH,
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertIsArray($body['data'] ?? null);

        $sessions = [];

        foreach ($body['data'] as $session) {
            $this->assertIsArray($session);
            $this->assertIsString($session['id'] ?? null);
            $this->assertIsBool($session['current'] ?? null);
            $sessions[] = ['id' => $session['id'], 'current' => $session['current']];
        }

        return $sessions;
    }

    /**
     * @return list<string>
     */
    private function sessionIdsIn(string $status, ?string $userId = null): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT CAST(id AS text) FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = :status'
            . ' ORDER BY id',
            ['id' => $userId ?? $this->userId, 'status' => $status],
        );

        return \array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    private function revokedEventCount(string $sessionId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM event_store WHERE aggregate_id = CAST(:id AS uuid) AND event_name = :name',
            ['id' => $sessionId, 'name' => 'erpify.iam.session.revoked'],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
