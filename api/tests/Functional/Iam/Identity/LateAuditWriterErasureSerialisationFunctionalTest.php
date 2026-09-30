<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use ArrayObject;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\IdentityRowLock;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Application\RecordLockoutNoticeAuditBestEffort;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Iam\Identity\Application\RecoveryThrottleAuditBudget;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalIdentityRowLock;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\Iam\Identity\Fixtures\SubjectRowLockProbingAuditLogger;
use Erpify\Tests\Functional\ResolvesContainerServices;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The four audit writers that name a person OUTSIDE any use case — the lockout and lockout-notice projections,
 * the recovery-throttle projection from `kernel.terminate`, and the recovery-secret projection after each
 * transition commits — each serialise on the subject's `identity_user` row, so none of them can commit a row
 * naming the subject after an erasure's pass over the trail has run.
 *
 * What a unit double cannot say is that the wait is real, so this asks Postgres. The erasure's hold on the row
 * is played by a SEPARATE PROCESS (`Fixtures/hold-and-erase-identity.php`) taking `SELECT … FOR UPDATE` on it,
 * holding it, deleting it and committing — exactly what `FulfilIdentityErasure` does between its administrator
 * re-check and its commit. It has to be another process: this one is blocked inside the writer for as long as
 * the row is held, so nothing here could commit the delete the writer is waiting on.
 *
 * The fixtures are COMMITTED, because the other session has to see and lock the subject; `tearDown()` removes
 * the identity and every row these writers added, however the test ends.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversClass(DbalIdentityRowLock::class)]
#[CoversClass(RecordLockoutAuditBestEffort::class)]
#[CoversClass(RecordLockoutNoticeAuditBestEffort::class)]
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
#[CoversClass(RecordRecoverySecretAuditBestEffort::class)]
final class LateAuditWriterErasureSerialisationFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const array ACTIONS = [
        'USER_LOCKED',
        'ACCOUNT_LOCKOUT_NOTIFIED',
        'PASSWORD_RECOVERY_THROTTLED',
        'RECOVERY_SECRET_MINTED',
    ];

    /** How long the other process holds the row before it deletes and commits: well inside the writers' bound. */
    private const int HOLD_MICROSECONDS = 400_000;

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    /**
     * The holding process while it runs, so `tearDown()` can end one a failed assertion left behind — alive, it
     * would go on to delete the subject under whatever test comes next.
     *
     * @var resource|null
     */
    private $holder;

    private string $subjectId;

    private string $subjectEmail;

    private TestHandler $reports;

    /** @var list<string> the writers' rows already present, so only the ones this test adds are removed */
    private array $rowsBefore = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->subjectId = Uuid::generate();
        $this->subjectEmail = 'late-audit-' . $this->subjectId . '@erpify.test';
        $this->reports = new TestHandler();

        $this->rowsBefore = $this->writerRowIds();
        $this->seedCommittedSubject();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $this->endHolder();
        $outside = $this->outsideConnection();

        if ($outside->isTransactionActive()) {
            $outside->rollBack();
        }

        $added = \array_values(\array_diff($this->writerRowIds(), $this->rowsBefore));

        if ([] !== $added) {
            $outside->executeStatement(
                'DELETE FROM audit_log WHERE id IN (:ids)',
                ['ids' => $added],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $outside->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $outside->close();
        $this->outside = null;
        parent::tearDown();
    }

    /**
     * The positive control: with nobody holding the row, each writer names the subject. Without it the cases
     * below would pass just as well over writers that never write.
     */
    #[Test]
    #[DataProvider('writers')]
    public function eachWriterNamesALiveSubject(string $writer, string $action): void
    {
        $this->runWriter($writer);

        $this->assertSame([], $this->reports->getRecords(), 'the writer reported no failure');
        $this->assertSame([$action], $this->actionsNamingTheSubject());
    }

    /**
     * "Locked, then written" holds just as well when the lock is taken in one transaction and released before
     * the INSERT runs in a second — the shape that reopens the window an erasure slips through. Only asking a
     * second connection at the instant of the write separates the two: `55P03` there means the writer's own
     * transaction still holds the subject row while it writes.
     */
    #[Test]
    #[DataProvider('writers')]
    public function eachWriterWritesWhileItsOwnTransactionStillHoldsTheSubjectRow(string $writer, string $action): void
    {
        $probing = new SubjectRowLockProbingAuditLogger(
            $this->service(AuditLogger::class),
            $this->outsideConnection(),
            $this->subjectId,
        );

        $this->runWriter($writer, $probing);

        $this->assertSame([], $this->reports->getRecords(), 'the writer reported no failure');
        $this->assertSame(
            [['action' => $action, 'rowHeld' => true]],
            $probing->writes,
            'the write ran while the subject row was locked by the transaction that makes it',
        );
    }

    /**
     * The four writers and the action each writes.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function writers(): iterable
    {
        foreach (self::writersAgainstAnErasedSubject() as $name => [$writer, $action]) {
            yield $name => [$writer, $action];
        }
    }

    /**
     * The race itself. The other process holds the row as an erasure does, then deletes it and commits; the
     * writer, started while the row is held, must WAIT — the elapsed time is what says it did — and then, with
     * its locked read re-evaluated against the committed delete, find no subject and write no row naming one.
     * A writer that did not lock would have written its row at once, naming a subject an instant from erasure.
     */
    #[Test]
    #[DataProvider('writersAgainstAnErasedSubject')]
    public function aWriterWaitsForTheErasureHoldingTheRowAndThenNamesNobody(
        string $writer,
        string $action,
        int $resourceLessRowsWhenAbsent,
    ): void {
        $resourceLessBefore = $this->rowsWithoutSubject($action);
        // Built before the row is held, so the time spent resolving services is not taken out of the hold.
        $run = $this->writerFor($writer);
        $holder = $this->holdAndEraseInAnotherProcess();

        $started = \microtime(true);
        $run();
        $waited = \microtime(true) - $started;

        $this->assertSame('deleted 1', $this->finish($holder), 'the other process erased the subject');
        $this->assertGreaterThan(0.25, $waited, 'the writer waited for the transaction holding the row');
        $this->assertOnlyAbsenceReported($writer);
        $this->assertSame([], $this->actionsNamingTheSubject(), 'no row names the subject erased under it');
        $this->assertSame($resourceLessBefore + $resourceLessRowsWhenAbsent, $this->rowsWithoutSubject($action));
    }

    /**
     * An erasure that committed before the writer arrived. This proves the ABSENCE guard — a writer finding no
     * row writes nothing naming it — and not the lock: an unlocked lookup would pass it just as well, which is why
     * the race above is the case that carries the serialisation.
     */
    #[Test]
    #[DataProvider('writersAgainstAnErasedSubject')]
    public function aWriterArrivingAfterTheErasureCommittedNamesNobody(
        string $writer,
        string $action,
        int $resourceLessRowsWhenAbsent,
    ): void {
        $this->outsideConnection()->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $resourceLessBefore = $this->rowsWithoutSubject($action);

        $this->runWriter($writer);

        $this->assertOnlyAbsenceReported($writer);
        $this->assertSame([], $this->actionsNamingTheSubject(), 'no row names the erased subject');
        $this->assertSame($resourceLessBefore + $resourceLessRowsWhenAbsent, $this->rowsWithoutSubject($action));
    }

    /**
     * The same four, with how many resource-less rows each writes once the subject is gone: the throttle still
     * reports the throttle, as for an address that never named anyone.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function writersAgainstAnErasedSubject(): iterable
    {
        yield 'lockout' => ['lockout', 'USER_LOCKED', 0];
        yield 'lockout notice' => ['lockout notice', 'ACCOUNT_LOCKOUT_NOTIFIED', 0];
        yield 'recovery throttle' => ['recovery throttle', 'PASSWORD_RECOVERY_THROTTLED', 1];
        yield 'recovery secret' => ['recovery secret', 'RECOVERY_SECRET_MINTED', 0];
    }

    /**
     * The wait is bounded: a row held past the bound is given up on, the projection skipped and reported as the
     * LOCK phase — never a login refusal or a worker held open for as long as an erasure runs. The session's
     * `statement_timeout` is only the net that keeps a regression from hanging the suite; if the bound were gone
     * that net is what would fire, with a different SQLSTATE.
     */
    #[Test]
    #[DataProvider('provideARowHeldPastTheBoundIsGivenUpOnAndReportedAsTheLockPhaseCases')]
    public function aRowHeldPastTheBoundIsGivenUpOnAndReportedAsTheLockPhase(string $writer): void
    {
        $run = $this->writerFor($writer);
        $outside = $this->outsideConnection();
        $outside->beginTransaction();
        $outside->fetchOne(
            'SELECT id FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $this->subjectId],
        );

        $writers = $this->entityManager->getConnection();
        $writers->executeStatement("SET statement_timeout = '10s'");

        try {
            $started = \microtime(true);
            $run();
            $waited = \microtime(true) - $started;
        } finally {
            $writers->executeStatement('RESET statement_timeout');
            $outside->rollBack();
        }

        $records = $this->reports->getRecords();
        $this->assertCount(1, $records, 'the skipped projection was reported');
        $record = $records[0] ?? null;
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame('lock', $record->context['phase'] ?? null);
        $this->assertSame('55P03', $this->sqlStateOf($record->context['exception'] ?? null));
        $this->assertGreaterThan(1.5, $waited, 'the writer waited out its bound');
        $this->assertLessThan(8.0, $waited, 'and no longer');
        $this->assertSame([], $this->actionsNamingTheSubject(), 'no row named the subject while it was held');
    }

    /**
     * The four writers alone, the address-keyed throttle among them: its lock is taken through
     * `whileHeldByEmail()`, a second statement the bound has to reach as well.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideARowHeldPastTheBoundIsGivenUpOnAndReportedAsTheLockPhaseCases(): iterable
    {
        foreach (self::writersAgainstAnErasedSubject() as $name => [$writer]) {
            yield $name => [$writer];
        }
    }

    /**
     * A refused nested call is what keeps the lock's two promises — its own bound, its own commit — from both
     * quietly becoming the caller's. The operation must not run: nothing may be written into a transaction the
     * row would then commit with.
     */
    #[Test]
    public function aLockAskedForInsideAnOpenTransactionIsRefusedAndRunsNothing(): void
    {
        $identityRows = $this->service(IdentityRowLock::class);
        $connection = $this->entityManager->getConnection();
        $ran = new ArrayObject();
        $connection->beginTransaction();

        try {
            $this->assertRefused(fn () => $identityRows->whileHeld(
                $this->subjectId,
                static fn (): null => $ran->append('whileHeld'),
            ));
            $this->assertRefused(fn () => $identityRows->whileHeldByEmail(
                Email::from($this->subjectEmail),
                static fn (): null => $ran->append('whileHeldByEmail'),
            ));
        } finally {
            $connection->rollBack();
        }

        $this->assertCount(0, $ran, 'no operation ran');
        $this->assertFalse($connection->isTransactionActive(), 'the refusal opened nothing of its own');
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
        } catch (LogicException $logicException) {
            $this->assertStringContainsString('transaction of its own', $logicException->getMessage());

            return;
        }

        $this->fail('a lock asked for inside an open transaction was accepted');
    }

    private function runWriter(string $writer, ?AuditLogger $auditLogger = null): void
    {
        ($this->writerFor($writer, $auditLogger))();
    }

    /**
     * @return callable(): void
     */
    private function writerFor(string $writer, ?AuditLogger $auditLogger = null): callable
    {
        $identityRows = $this->service(IdentityRowLock::class);
        $auditLogger ??= $this->service(AuditLogger::class);
        $logger = new Logger('late-audit-writer', [$this->reports]);

        return match ($writer) {
            'lockout' => fn () => (new RecordLockoutAuditBestEffort($identityRows, $auditLogger, $logger))
                ->record($this->subjectId),
            'lockout notice' => fn () => (
                new RecordLockoutNoticeAuditBestEffort($identityRows, $auditLogger, $logger)
            )->record($this->subjectId, null),
            'recovery throttle' => fn () => (new RecordRecoveryThrottleAuditBestEffort(
                $this->alwaysGrantedBudget(),
                $identityRows,
                $auditLogger,
                $logger,
            ))->record($this->subjectEmail),
            'recovery secret' => fn () => (
                new RecordRecoverySecretAuditBestEffort($identityRows, $auditLogger, $logger)
            )->recordMinted($this->subjectId),
            default => $this->fail("unknown writer {$writer}"),
        };
    }

    /**
     * An erased subject is an outcome, never a failure: nothing at `error`. The id-keyed writers still say, once
     * and at `info`, that they withheld their row; the throttle does not, because an address that resolves to
     * nobody is its ordinary case and it writes its resource-less row instead.
     */
    private function assertOnlyAbsenceReported(string $writer): void
    {
        $records = $this->reports->getRecords();

        if ('recovery throttle' === $writer) {
            $this->assertSame([], $records, 'an erased subject is an outcome, not a failure');

            return;
        }

        $this->assertCount(1, $records, 'the withheld row was reported once');
        $record = $records[0] ?? null;
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame(Level::Info, $record->level, 'an erased subject is an outcome, not a failure');
        $this->assertSame('subject_absent', $record->context['phase'] ?? null);
        $this->assertStringNotContainsString(
            $this->subjectId,
            \json_encode([$record->message, $record->context], JSON_THROW_ON_ERROR),
            'the report names no subject',
        );
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the budget's $email is mandated by the interface
     */
    private function alwaysGrantedBudget(): RecoveryThrottleAuditBudget
    {
        return new class implements RecoveryThrottleAuditBudget {
            #[Override]
            public function claimFor(#[SensitiveParameter] string $email): bool
            {
                return true;
            }
        };
    }

    /**
     * Starts the other process and returns once it reports holding the row, so the writer is guaranteed to
     * arrive while it is held. Its stderr is read only on failure: reading it here would block until the process
     * ends, and the writer would then arrive after the erasure rather than during it.
     *
     * @return array{resource, resource, resource} the process, its stdout and its stderr
     */
    private function holdAndEraseInAnotherProcess(): array
    {
        $process = \proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/hold-and-erase-identity.php',
                $this->subjectId,
                (string) self::HOLD_MICROSECONDS,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'the holding process started');
        [$stdin, $stdout, $stderr] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        \fwrite($stdin, \json_encode($this->connectionParameters(), JSON_THROW_ON_ERROR));
        \fclose($stdin);

        $reported = \fgets($stdout);

        $this->holder = $process;

        if ("locked\n" !== $reported) {
            $this->fail('the other process did not hold the subject row: ' . \stream_get_contents($stderr));
        }

        return [$process, $stdout, $stderr];
    }

    /**
     * @param array{resource, resource, resource} $holder
     */
    private function finish(array $holder): string
    {
        [$process, $stdout, $stderr] = $holder;
        $output = \trim((string) \stream_get_contents($stdout));
        $errors = (string) \stream_get_contents($stderr);
        \fclose($stdout);
        \fclose($stderr);

        $this->holder = null;
        $this->assertSame(0, \proc_close($process), 'the holding process exited cleanly: ' . $errors);

        return $output;
    }

    private function endHolder(): void
    {
        if (!\is_resource($this->holder)) {
            return;
        }

        \proc_terminate($this->holder, 9);
        \proc_close($this->holder);
        $this->holder = null;
    }

    /**
     * Only what a connection needs, so nothing the bundle adds (a middleware, a wrapper class) has to survive
     * a JSON round trip into the other process.
     *
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
     * @return list<string>
     */
    private function actionsNamingTheSubject(): array
    {
        /** @var list<string> */
        return $this->outsideConnection()->fetchFirstColumn(
            'SELECT action FROM audit_log WHERE resource_id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
    }

    private function rowsWithoutSubject(string $action): int
    {
        $count = $this->outsideConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action AND resource_id IS NULL',
            ['action' => $action],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function writerRowIds(): array
    {
        /** @var list<string> */
        return $this->outsideConnection()->fetchFirstColumn(
            'SELECT id::text FROM audit_log WHERE action IN (:actions)',
            ['actions' => self::ACTIONS],
            ['actions' => ArrayParameterType::STRING],
        );
    }

    /**
     * The SQLSTATE survives on the driver exception somewhere down the `previous` chain, whatever wraps it.
     */
    private function sqlStateOf(mixed $throwable): ?string
    {
        while ($throwable instanceof Throwable) {
            if ($throwable instanceof DriverException) {
                return $throwable->getSQLState();
            }

            $throwable = $throwable->getPrevious();
        }

        return null;
    }

    private function seedCommittedSubject(): void
    {
        $user = UserFixtureFactory::create($this->subjectId, $this->subjectEmail, 'late-audit-password');
        $user->pullDomainEvents();
        // `save()` flushes; only the identity map needs clearing so nothing reads the row from memory.
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }
}
