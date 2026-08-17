<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\Iam\Identity\Fixtures\ThrowingLockoutAuditWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two halves of the observable this change exists for, on the lockout path: a failed `security` audit
 * projection ARRIVES in a log, and it arrives WITHOUT activating the buffered handler.
 *
 * Neither half is reachable from a unit test, and the distinction is what makes this worth a real request.
 * `RecordLockoutAuditBestEffortTest` pins the LEVEL against a recording double, which is exactly as true when
 * the record is discarded as when it survives — it can only observe what the source calls, never what Monolog
 * keeps. What decides that is `config/packages/monolog.yaml` plus the channel binding in `services.yaml`, and
 * that binding is the fragile half: `FileLoader::registerClasses()` overwrites definitions unconditionally,
 * so moving the explicit block above the `Erpify\` prototype would silently revert this class to the
 * autowired `app` channel with every static gate still green.
 *
 * The second half has the teeth. On `app` at `error` the record does not merely go missing — it ACTIVATES
 * `fingers_crossed` and flushes every record the request accumulated. Asserting the buffered log did not grow
 * is what separates "reported" from "reported at the price of whatever else that request logged", and it is
 * the assertion that goes red on the reordering above.
 *
 * The trigger is a real tenth failed login, not a hand-called use case: the report only exists because the
 * lockout fired, and driving it through HTTP is the only way the buffered handler is in play at all.
 *
 * @internal
 */
#[CoversNothing]
final class LockoutAuditWriteFailureArrivalTest extends WebTestCase
{
    private const string FAILURE_MESSAGE = 'Lockout committed; security audit projection skipped (write failed).';

    private const string LOGIN_ROUTE = '/api/v1/backoffice/login';

    private const string SUBJECT_EMAIL = 'lockout-arrival@erpify.test';

    private const string SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71';

    private const string SUBJECT_PASSWORD = 'lockout-arrival-password';

    /**
     * The login origin the CSRF guard requires; an absent or foreign one is refused before authentication,
     * which would make the missing report mean "never attempted" instead of "discarded".
     */
    private const string ALLOWED_ORIGIN = 'http://localhost';

    #[Test]
    public function aFailedLockoutProjectionArrivesWithoutFlushingTheBufferedHandler(): void
    {
        $client = self::createClient();

        // Registered before anything resolves the port, and `disableReboot` keeps the double in the container
        // the login request resolves from.
        $writer = new ThrowingLockoutAuditWriter();
        self::getContainer()->set(AuditLogWriter::class, $writer);
        $client->disableReboot();

        $this->seedSubjectOneAttemptFromTheLock();

        // The suite shares logs nothing truncates, so only the tail each file gains during THIS request can be
        // evidence. The stat cache is dropped before the offsets are taken as well as after: a stale-short
        // offset widens the window that is read, making the growth assertion easier rather than harder.
        $environment = self::getContainer()->getParameter('kernel.environment');
        $this->assertIsString($environment);
        $observability = $this->logFile('observability');
        $buffered = $this->logFile($environment);
        $observabilityBefore = $this->sizeOf($observability);
        $bufferedBefore = $this->sizeOf($buffered);

        $this->postTheAttemptThatTripsTheLock($client);

        $this->assertSame(
            1,
            $writer->attempts,
            'the lockout projection was actually attempted and threw — an arrival claim over a write that '
            . 'never happened proves nothing',
        );

        $appended = $this->tailOf($observability, $observabilityBefore);
        $this->assertStringContainsString(
            self::FAILURE_MESSAGE,
            $appended,
            'the swallowed lockout projection reached the always-on log this request wrote',
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
            'the buffered handler never activated: reporting the loss must not flush a request buffer that '
            . 'holds every other record this request accumulated',
        );
    }

    /**
     * The tenth wrong password, and the assertion that it landed where the report can exist.
     *
     * The uniform 401 is the contract — the lock is never revealed to an anonymous caller — and asserting it
     * keeps a rejected Origin (400) or an already-locked subject (403) from being read as a discarded report.
     */
    private function postTheAttemptThatTripsTheLock(KernelBrowser $client): void
    {
        $client->request(
            Request::METHOD_POST,
            self::LOGIN_ROUTE,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => self::ALLOWED_ORIGIN],
            content: \json_encode(
                ['email' => self::SUBJECT_EMAIL, 'password' => 'wrong-password'],
                JSON_THROW_ON_ERROR,
            ),
        );

        $response = $client->getResponse();
        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
    }

    /**
     * Seeds the subject with one attempt left, so a single wrong password trips the lock inside the request.
     */
    private function seedSubjectOneAttemptFromTheLock(): void
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
            'UPDATE identity_user SET failed_attempts = :attempts WHERE email = :email',
            ['attempts' => User::MAX_FAILED_ATTEMPTS - 1, 'email' => self::SUBJECT_EMAIL],
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
        $this->assertGreaterThan($offset, (int) \filesize($file), 'the request appended nothing to search');

        $appended = \file_get_contents($file, offset: $offset);
        $this->assertIsString($appended);

        return $appended;
    }
}
