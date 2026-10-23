<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalSessionSubjectLock;
use Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A login whose identity is being suspended or deactivated must not leave that identity a live session once the
 * write has committed. The status writer's revoke-everything runs after its own commit, so a session minted while
 * the write was in flight would land after the revocation and survive it for its whole TTL — the mint therefore
 * holds the identity `FOR SHARE`, waits for the write, and reads the status it committed.
 *
 * The write is played by another process (`Fixtures/hold-and-suspend-identity.php`): the login blocks on the row
 * that process holds, so nothing in this process could commit it. That process does not sleep for a guessed
 * interval — it keeps its transaction open until it SEES a backend waiting on the lock it holds
 * (`pg_blocking_pids()`), bounded at ten seconds, and only then commits; it reports which of the two happened. So
 * "the login waited for the write" is an observation, not an inference from elapsed time.
 *
 * Two writer shapes: the explicit `FOR UPDATE` every status writer here takes, and a bare `UPDATE`, which holds
 * only `FOR NO KEY UPDATE` and is the one a `FOR KEY SHARE` hold would not wait for. Measured with the mint's hold
 * at `FOR KEY SHARE`, the bare-`UPDATE` cases never wait and mint an `ACTIVE` session for the walled identity.
 *
 * The reverse order — the login committing first — is driven through the real status change, whose revocation
 * then retires the session the login minted. The positive control logs in with nothing in the way.
 *
 * Rows are committed — the other process sees nothing else — so teardown removes them by id however the test
 * ends, and kills the other process if it is still running.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — a race through the whole kernel: the login, the status
 * change, the other process and the rows it all leaves behind are each a real participant.
 */
#[CoversClass(StartSession::class)]
#[CoversClass(DbalSessionSubjectLock::class)]
#[CoversClass(SessionMintingSuccessListener::class)]
final class LoginRacingASuspensionFunctionalTest extends WebTestCase
{
    use ResolvesContainerServices;
    use SeedsAnOrganizationMember;

    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string PASSWORD = 'the-password-of-a-member-being-walled';

    private KernelBrowser $client;

    private Connection $connection;

    private ?Connection $outside = null;

    /** @var resource|null */
    private $writer;

    private string $userId = '';

    private string $email = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $this->userId = Uuid::generate();
        $this->email = \sprintf('status-race-%s@erpify.test', \str_replace('-', '', $this->userId));
        $this->seedAnOrganizationMember($this->userId, $this->email, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->writer)) {
            \proc_terminate($this->writer, 9);
            \proc_close($this->writer);
            $this->writer = null;
        }

        if ('' !== $this->userId) {
            $outside = $this->outsideConnection();
            $outside->executeStatement(
                'DELETE FROM event_store WHERE aggregate_id = CAST(:u AS UUID) OR aggregate_id IN '
                . '(SELECT id FROM iam_session WHERE user_id = CAST(:u AS UUID))',
                ['u' => $this->userId],
            );

            foreach (['DELETE FROM iam_session WHERE user_id', 'DELETE FROM audit_log WHERE actor_id'] as $delete) {
                $outside->executeStatement($delete . ' = CAST(:u AS UUID)', ['u' => $this->userId]);
            }

            $this->forgetTheOrganizationMember($this->userId, $this->email);
            $outside->close();
            $this->outside = null;
        }

        parent::tearDown();
    }

    #[DataProvider('provideALoginArrivingBehindAStatusWriteWaitsForItAndMeetsItsWallCases')]
    public function testALoginArrivingBehindAStatusWriteWaitsForItAndMeetsItsWall(
        string $shape,
        IdentityStatus $status,
        string $wall,
    ): void {
        [$process, $stdout, $stderr] = $this->writeInAnotherProcess($shape, $status);

        $this->login();
        $response = $this->client->getResponse();

        $output = \trim((string) \stream_get_contents($stdout));
        $errors = (string) \stream_get_contents($stderr);
        \fclose($stdout);
        \fclose($stderr);
        $exitCode = \proc_close($process);
        $this->writer = null;

        $this->assertSame(
            "waited\ncommitted 1",
            $output,
            'the login never waited on the status write in flight: ' . $errors,
        );
        $this->assertSame(0, $exitCode, 'the writing process exited cleanly: ' . $errors);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('"type":"' . $wall . '"', (string) $response->getContent());
        $this->assertSame($status->value, $this->identityStatus());
        $this->assertSame(0, $this->sessionCount('ACTIVE'), 'no live session names the walled identity');
    }

    /**
     * @return iterable<string, array{string, IdentityStatus, string}>
     */
    public static function provideALoginArrivingBehindAStatusWriteWaitsForItAndMeetsItsWallCases(): iterable
    {
        yield 'suspended under FOR UPDATE' => ['for-update', IdentityStatus::SUSPENDED, 'account-suspended'];
        yield 'suspended by a bare UPDATE' => ['plain', IdentityStatus::SUSPENDED, 'account-suspended'];
        yield 'deactivated under FOR UPDATE' => ['for-update', IdentityStatus::DEACTIVATED, 'account-deactivated'];
        yield 'deactivated by a bare UPDATE' => ['plain', IdentityStatus::DEACTIVATED, 'account-deactivated'];
    }

    /**
     * The other order: the login commits its session first, and the suspension's own revocation then retires it.
     */
    public function testASuspensionLandingAfterTheLoginRevokesTheSessionItMinted(): void
    {
        $this->login();
        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame(1, $this->sessionCount('ACTIVE'), 'the login minted its session');

        $this->service(ChangeUserStatus::class)->suspend($this->userId);

        $this->assertSame(IdentityStatus::SUSPENDED->value, $this->identityStatus());
        $this->assertSame(0, $this->sessionCount('ACTIVE'), 'no live session survives the suspension');
        $this->assertSame(1, $this->sessionCount('REVOKED'), 'the session the login minted was revoked');
    }

    public function testWithNothingInTheWayTheLoginMintsItsSession(): void
    {
        $this->login();

        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame(1, $this->sessionCount('ACTIVE'));
    }

    private function login(): void
    {
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost',
            ],
            content: \json_encode(['email' => $this->email, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Returns once the other process holds the identity's row with its write in it. Its stderr is read only on
     * failure: reading it here would block until the process ends, after the window the login must enter.
     *
     * @return array{resource, resource, resource} the process, its stdout and its stderr
     */
    private function writeInAnotherProcess(string $shape, IdentityStatus $status): array
    {
        $process = \proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/hold-and-suspend-identity.php',
                $this->userId,
                $shape,
                $status->value,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'the writing process started');
        [$stdin, $stdout, $stderr] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);
        $this->writer = $process;

        \fwrite($stdin, \json_encode($this->connectionParameters(), JSON_THROW_ON_ERROR));
        \fclose($stdin);

        if ("locked\n" !== \fgets($stdout)) {
            $this->fail('the other process did not write the identity: ' . \stream_get_contents($stderr));
        }

        return [$process, $stdout, $stderr];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParameters(): array
    {
        return \array_intersect_key(
            $this->connection->getParams(),
            \array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'charset', 'serverVersion']),
        );
    }

    private function identityStatus(): string
    {
        $status = $this->outsideConnection()->fetchOne(
            'SELECT status FROM identity_user WHERE id = CAST(:u AS UUID)',
            ['u' => $this->userId],
        );
        $this->assertIsString($status);

        return $status;
    }

    private function sessionCount(string $status): int
    {
        $count = $this->outsideConnection()->fetchOne(
            'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:u AS UUID) AND status = :status',
            ['u' => $this->userId, 'status' => $status],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->connection->getParams());
        }

        return $this->outside;
    }
}
