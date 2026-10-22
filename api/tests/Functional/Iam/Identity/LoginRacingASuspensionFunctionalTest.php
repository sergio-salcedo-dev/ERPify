<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalSessionSubjectLock;
use Erpify\Iam\Session\Application\SessionSubjectLock;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Exception\SessionSubjectNotAdmissible;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A login whose identity is being suspended must not leave that identity a live session once the suspension has
 * committed. The suspension's revoke-everything runs after its own commit, so a session minted while the status
 * write was in flight would land after the revocation and survive it, for its whole TTL — the mint therefore holds
 * the identity under `FOR SHARE` with an `ACTIVE` predicate, waits for the status write and re-reads it.
 *
 * The status write is played by another process (`Fixtures/hold-and-suspend-identity.php`) for the reason
 * {@see LoginRacingAnErasureFunctionalTest} gives: one PHP process blocked inside the login could never commit
 * the write it waits on. That process updates the status inside an open transaction, reports, waits and commits,
 * so the login starts strictly inside the window. Measured with the mint's lock at `FOR KEY SHARE`, the login
 * does not wait, mints, and this test reads one `ACTIVE` session for a `SUSPENDED` identity.
 *
 * The positive control mints through the same wiring with no status write in the way, so the zeros of the race
 * are the lock's doing and not a login that never writes anything. Rows are committed — the other process sees
 * nothing else — so teardown removes them by id however the test ends.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(StartSession::class)]
#[CoversClass(DbalSessionSubjectLock::class)]
final class LoginRacingASuspensionFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string STARTED_EVENT = 'erpify.iam.session.started';

    /** Long enough that a login which does not wait would finish well inside it. */
    private const int HOLD_MICROSECONDS = 800_000;

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    /** @var resource|null */
    private $holder;

    private string $subjectId = '';

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->subjectId = Uuid::generate();
        $this->seedCommittedSubject();
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
            'DELETE FROM event_store WHERE aggregate_id IN '
            . '(SELECT id FROM iam_session WHERE user_id = CAST(:u AS UUID)) OR payload::text ILIKE :pattern',
            ['u' => $this->subjectId, 'pattern' => '%' . $this->subjectId . '%'],
        );
        $connection->executeStatement(
            'DELETE FROM iam_session WHERE user_id = CAST(:u AS UUID)',
            ['u' => $this->subjectId],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:u AS UUID)',
            ['u' => $this->subjectId],
        );
        $connection->close();

        $this->outside = null;
        parent::tearDown();
    }

    #[Test]
    public function aLoginArrivingBehindASuspensionWaitsForItAndMintsNothing(): void
    {
        [$process, $stdout, $stderr] = $this->holdAndSuspendInAnotherProcess();

        $started = \hrtime(true);

        try {
            $this->login();
            $this->fail('a login for an identity suspended while it waited must fail closed');
        } catch (SessionSubjectNotAdmissible) {
            // The failure the minting listener turns into a dropped cookie and a 401.
        }

        $elapsedMicroseconds = (\hrtime(true) - $started) / 1_000;

        $output = \trim((string) \stream_get_contents($stdout));
        $errors = (string) \stream_get_contents($stderr);
        \fclose($stdout);
        \fclose($stderr);
        $this->holder = null;
        $this->assertSame(0, \proc_close($process), 'the suspending process exited cleanly: ' . $errors);
        $this->assertSame('suspended 1', $output, 'the other process suspended the identity');

        $this->assertGreaterThan(
            self::HOLD_MICROSECONDS / 2,
            $elapsedMicroseconds,
            'the login did not wait on the status write in flight, so the suspended identity was minted a session',
        );
        $this->assertSame(0, $this->sessionCount(), 'no session row names the suspended identity');
        $this->assertSame(0, $this->startedEventCount(), 'and no SessionStarted announces one');
    }

    #[Test]
    public function withNoSuspensionTheSameLoginMintsAndAnnouncesItsSession(): void
    {
        $this->login();

        $this->assertSame(1, $this->sessionCount());
        $this->assertSame(1, $this->startedEventCount());
    }

    private function login(): void
    {
        $lock = $this->service(SessionSubjectLock::class);
        $this->assertInstanceOf(DbalSessionSubjectLock::class, $lock, 'the deployed adapter is the one under test');

        $startSession = new StartSession(
            $this->service(SessionRepository::class),
            new RecordingCurrentSessionReference(),
            $this->service(EventBus::class),
            $this->service(TransactionManager::class),
            $this->service(Clock::class),
            $lock,
        );

        $startSession->start($this->subjectId, Uuid::generate(), 'phpunit', null);
    }

    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            'suspension-race-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    /**
     * Returns once the other process holds the identity's row. Its stderr is read only on failure: reading it here
     * would block until the process ends, after the window the login must enter.
     *
     * @return array{resource, resource, resource} the process, its stdout and its stderr
     */
    private function holdAndSuspendInAnotherProcess(): array
    {
        $process = \proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/hold-and-suspend-identity.php',
                $this->subjectId,
                (string) self::HOLD_MICROSECONDS,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'the suspending process started');
        [$stdin, $stdout, $stderr] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);
        $this->holder = $process;

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
            $this->entityManager->getConnection()->getParams(),
            \array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'charset', 'serverVersion']),
        );
    }

    private function sessionCount(): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:u AS UUID)',
            ['u' => $this->subjectId],
        );
    }

    private function startedEventCount(): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM event_store WHERE event_name = :name AND payload::text ILIKE :pattern',
            ['name' => self::STARTED_EVENT, 'pattern' => '%' . $this->subjectId . '%'],
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
