<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An erasure that meets a session revocation already in flight must still leave no business-log row naming
 * the subject.
 *
 * The revocation holds the session row and has appended a `SessionRevoked` carrying the user's id, uncommitted.
 * The erasure's session purge waits on that row; what decides the outcome is whether the business-log pass runs
 * before that wait or after it. Before, the pass cannot see an uncommitted row and the event commits behind it
 * naming the person for ever. After, the pass is a fresh statement under READ COMMITTED and rewrites it.
 *
 * The revocation is played by another process (`Fixtures/hold-and-revoke-session.php`) because the image
 * has no `pcntl` and no asynchronous `pgsql`: one PHP process blocked inside the erasure could never commit
 * the transaction the erasure is waiting on. It reports only once its lock AND its append are in place, so the
 * erasure starts strictly inside the window — the ordering is staged, never left to timing.
 *
 * The fixtures are COMMITTED, because a row inside a rolled-back transaction is invisible to the other
 * process; they are removed by id however the test ends. The audit logger is doubled for the reason
 * {@see ErasureLockOrderFunctionalTest} gives: it lies outside the property and the real one would leave
 * `security` rows other tests read.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class ErasureWaitsForAnInFlightSessionRevocationFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4b02';

    /**
     * Long enough that an erasure which does not wait would finish well inside it, which is what the elapsed
     * time assertion distinguishes.
     */
    private const int HOLD_MICROSECONDS = 800_000;

    private string $subjectId;

    private string $sessionId;

    private string $eventId;

    private ?Connection $outside = null;

    /** @var resource|null */
    private $holder;

    private EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->subjectId = Uuid::generate();
        $this->sessionId = Uuid::generate();
        $this->eventId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->holder)) {
            \proc_terminate($this->holder, 9);
            \proc_close($this->holder);
            $this->holder = null;
        }

        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $connection = $this->outsideConnection();
        $connection->executeStatement(
            'DELETE FROM event_store WHERE event_id = CAST(:id AS UUID) OR aggregate_id = CAST(:session AS UUID)',
            ['id' => $this->eventId, 'session' => $this->sessionId],
        );
        $connection->executeStatement(
            'DELETE FROM iam_session WHERE id = CAST(:id AS UUID)',
            ['id' => $this->sessionId],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $connection->close();

        $this->outside = null;
        parent::tearDown();
    }

    #[Test]
    public function aRevocationCommittingWhileTheErasureWaitsLeavesNoEventNamingTheSubject(): void
    {
        $this->seedCommittedSubjectWithOneSession();
        [$process, $stdout, $stderr] = $this->holdAndRevokeInAnotherProcess();

        $started = \hrtime(true);
        $result = $this->erasure()->execute($this->subjectId);
        $elapsedMicroseconds = (\hrtime(true) - $started) / 1_000;

        $output = \trim((string) \stream_get_contents($stdout));
        $errors = (string) \stream_get_contents($stderr);
        \fclose($stdout);
        \fclose($stderr);
        $this->holder = null;
        $this->assertSame(0, \proc_close($process), 'the revoking process exited cleanly: ' . $errors);
        $this->assertSame('committed', $output, 'the revocation committed');

        // Not decoration: an erasure that never met the held row proves nothing about waiting on it.
        $this->assertTrue($result->identityErased, 'the identity was live');
        $this->assertSame(1, $result->sessionsDeleted, 'the revoked session row was purged');
        $this->assertGreaterThan(
            self::HOLD_MICROSECONDS / 2,
            $elapsedMicroseconds,
            'the erasure did not wait on the session row the revocation held, so the race was never staged',
        );

        $this->assertSame(
            1,
            $this->scalar('SELECT COUNT(*) FROM event_store WHERE event_id = CAST(:id AS UUID)', [
                'id' => $this->eventId,
            ]),
            "the revocation's event is in the business log, so the assertion below is about a real row",
        );
        $this->assertSame(
            0,
            $this->scalar(
                'SELECT COUNT(*) FROM event_store WHERE aggregate_id = CAST(:subject AS UUID) '
                . 'OR payload::text ILIKE :pattern OR metadata::text ILIKE :pattern',
                ['subject' => $this->subjectId, 'pattern' => '%' . $this->subjectId . '%'],
            ),
            'A SessionRevoked committed while the erasure waited on its session row still names the subject: '
            . 'the business-log pass ran before the session purge, so it could not see the uncommitted event.',
        );
    }

    private function erasure(): FulfilIdentityErasure
    {
        return new FulfilIdentityErasure(
            $this->service(EraseIdentitySubject::class),
            $this->service(AuditSubjectTrailErasure::class),
            $this->service(EventStoreSubjectAnonymiser::class),
            $this->service(ActiveAdministratorDirectory::class),
            $this->service(PurgeUserSessions::class),
            $this->service(PurgeUserMembership::class),
            $this->service(PurgeUserInvitations::class),
            new RecordingAuditLogger(),
            $this->service(ActorContextFactory::class),
            $this->service(TransactionManager::class),
        );
    }

    private function seedCommittedSubjectWithOneSession(): void
    {
        $user = User::register(
            $this->subjectId,
            'session-race-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);

        $session = Session::start(
            $this->sessionId,
            $this->subjectId,
            self::ORGANIZATION_ID,
            'phpunit',
            null,
            SystemClock::now()->add(new DateInterval('P7D')),
        );
        $session->pullDomainEvents();
        $this->service(SessionRepository::class)->save($session);

        $this->entityManager->clear();
    }

    /**
     * Returns once the other process holds the session row and has appended its event. Its stderr is read only
     * on failure: reading it here would block until the process ends, after the window the erasure must enter.
     *
     * @return array{resource, resource, resource} the process, its stdout and its stderr
     */
    private function holdAndRevokeInAnotherProcess(): array
    {
        $process = \proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/hold-and-revoke-session.php',
                $this->sessionId,
                $this->subjectId,
                $this->eventId,
                (string) self::HOLD_MICROSECONDS,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'the revoking process started');
        [$stdin, $stdout, $stderr] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);
        $this->holder = $process;

        \fwrite($stdin, \json_encode($this->connectionParameters(), JSON_THROW_ON_ERROR));
        \fclose($stdin);

        if ("locked\n" !== \fgets($stdout)) {
            $this->fail('the other process did not hold the session row: ' . \stream_get_contents($stderr));
        }

        return [$process, $stdout, $stderr];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParameters(): array
    {
        return \array_intersect_key(
            $this->entityManager->getConnection()->getParams(),
            \array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'charset', 'serverVersion']),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function scalar(string $sql, array $parameters): int
    {
        $value = $this->outsideConnection()->fetchOne($sql, $parameters);
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }
}
