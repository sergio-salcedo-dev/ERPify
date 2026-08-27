<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\NotifyLockedIdentitiesHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\NotifyLockedIdentitiesMessage;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\Iam\Identity\Fixtures\ThrowingLockoutNoticeAuditWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two halves of the observable this change exists for, on the lockout-NOTICE path: a failed `security`
 * audit projection ARRIVES in a log, and it arrives WITHOUT activating the buffered handler.
 *
 * Mirrors {@see LockoutAuditWriteFailureArrivalTest} for the sibling TRIP row, and the reasoning it states for
 * itself carries over unchanged: `RecordLockoutNoticeAuditBestEffortTest` pins the LEVEL against a recording
 * double, which is exactly as true when the record is discarded as when it survives — it can only observe
 * what the source calls, never what Monolog keeps. What decides that is `config/packages/monolog.yaml` plus
 * the channel binding in `services.yaml`, and that binding is the fragile half: `FileLoader::registerClasses()`
 * overwrites definitions unconditionally, so moving the explicit block above the `Erpify\` prototype would
 * silently revert this class to the autowired `app` channel with every static gate still green. On `app` at
 * `error` the record does not merely go missing — it ACTIVATES `fingers_crossed` and flushes every record the
 * process accumulated, so asserting the buffered log did not grow is what separates "reported" from "reported
 * at the price of whatever else was logged".
 *
 * **The trigger is where this diverges from the sibling.** The notice row is written from
 * {@see \Erpify\Iam\Identity\Application\NotifyLockedIdentities}'s scheduled maintenance sweep, never from a
 * request — there is no login attempt that produces it, so driving it means invoking
 * {@see NotifyLockedIdentitiesHandler} exactly as the scheduler transport does, against a real kernel boot,
 * over a subject already locked and not yet notified.
 *
 * **The functional suite runs against the same database `dev` uses** (`DATABASE_URL` is a real container
 * environment variable, so `.env.test`'s override never applies), which the sibling test's own subject can
 * leave polluted: it seeds a lockout through a real login and never notifies it, so that identity can still be
 * `locked_until` in the future when this test's sweep runs and picks it up too. Asserting `$writer->attempts`
 * as exactly one would therefore be a claim this file cannot make truthfully — `>= 1` is what the shared
 * database actually supports, and the arrival assertion below still requires at least one real attempt.
 *
 * @internal
 */
#[CoversNothing]
final class LockoutNoticeAuditWriteFailureArrivalTest extends KernelTestCase
{
    private const string FAILURE_MESSAGE = 'Lockout notice sent; security audit projection skipped (write failed).';

    private const string SUBJECT_EMAIL = 'lockout-notice-arrival@erpify.test';

    private const string SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72';

    private const string SUBJECT_PASSWORD = 'lockout-notice-arrival-password';

    #[Test]
    public function aFailedLockoutNoticeProjectionArrivesWithoutFlushingTheBufferedHandler(): void
    {
        self::bootKernel();

        // Registered before anything resolves the writer, so the sweep's own `SymfonyAuditLogger` is built
        // against the double rather than the real one.
        $writer = new ThrowingLockoutNoticeAuditWriter();
        self::getContainer()->set(AuditLogWriter::class, $writer);

        $this->seedALockedNotYetNotifiedSubject();

        // The suite shares logs nothing truncates, so only the tail each file gains during THIS sweep can be
        // evidence. The stat cache is dropped before the offsets are taken as well as after: a stale-short
        // offset widens the window that is read, making the growth assertion easier rather than harder.
        $environment = self::getContainer()->getParameter('kernel.environment');
        $this->assertIsString($environment);
        $observability = $this->logFile('observability');
        $buffered = $this->logFile($environment);
        $observabilityBefore = $this->sizeOf($observability);
        $bufferedBefore = $this->sizeOf($buffered);

        $handler = self::getContainer()->get(NotifyLockedIdentitiesHandler::class);
        $this->assertInstanceOf(NotifyLockedIdentitiesHandler::class, $handler);
        $handler(new NotifyLockedIdentitiesMessage());

        $this->assertGreaterThanOrEqual(
            1,
            $writer->attempts,
            'the lockout-notice projection was actually attempted and threw — an arrival claim over a write '
            . 'that never happened proves nothing',
        );

        $appended = $this->tailOf($observability, $observabilityBefore);
        $this->assertStringContainsString(
            self::FAILURE_MESSAGE,
            $appended,
            'the swallowed lockout-notice projection reached the always-on log this sweep wrote',
        );
        // Read out of the bytes Monolog emitted rather than off a double: the test-env observability handler
        // is JSON-formatted, so the record carries its own level and a demotion cannot hide here.
        $this->assertStringContainsString(
            '"level_name":"ERROR"',
            $appended,
            'the arriving record is the error it claims to be',
        );

        $this->assertSame(
            $bufferedBefore,
            $this->sizeOf($buffered),
            'the buffered handler never activated: reporting the loss must not flush a process buffer that '
            . 'holds every other record this sweep accumulated',
        );
    }

    /**
     * Seeds a subject already locked and not yet notified, so the sweep's own query selects it and its own
     * `awaitsLockoutNoticeAt()` check admits it without needing a real tenth failed login.
     */
    private function seedALockedNotYetNotifiedSubject(): void
    {
        $container = self::getContainer();
        $users = $container->get(UserRepository::class);
        $this->assertInstanceOf(UserRepository::class, $users);
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE email = :email',
            ['email' => self::SUBJECT_EMAIL],
        );

        $user = UserFixtureFactory::create(self::SUBJECT_ID, self::SUBJECT_EMAIL, self::SUBJECT_PASSWORD);
        $users->save($user);
        $entityManager->flush();

        $connection->executeStatement(
            'UPDATE identity_user SET locked_until = :lockedUntil, lockout_notified_at = NULL '
            . 'WHERE email = :email',
            [
                'lockedUntil' => (new DateTimeImmutable())->add(new DateInterval('PT15M')),
                'email' => self::SUBJECT_EMAIL,
            ],
            ['lockedUntil' => Types::DATETIME_IMMUTABLE, 'email' => Types::STRING],
        );
        $entityManager->clear();
    }

    private function logFile(string $name): string
    {
        $logsDir = self::getContainer()->getParameter('kernel.logs_dir');
        $this->assertIsString($logsDir);

        return $logsDir . '/' . $name . '.log';
    }

    private function sizeOf(string $file): int
    {
        \clearstatcache(true, $file);

        return \is_file($file) ? (int) \filesize($file) : 0;
    }

    private function tailOf(string $file, int $offset): string
    {
        \clearstatcache(true, $file);
        $this->assertFileExists($file, 'nothing wrote the log this assertion reads');
        $this->assertGreaterThan($offset, (int) \filesize($file), 'the sweep appended nothing to search');

        $appended = \file_get_contents($file, offset: $offset);
        $this->assertIsString($appended);

        return $appended;
    }
}
