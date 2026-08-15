<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\Shared\Audit\Fixtures\ThrowingAuditLogWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two halves of the observable this change exists for: a failed `activity` write ARRIVES in a log, and it
 * arrives WITHOUT activating the buffered handler.
 *
 * A level assertion against an injected double is not the gate — `SymfonyAuditLoggerTest` already makes it,
 * and it was green for as long as the failure was unreportable in production, because it can only pin what
 * the source calls and never whether Monolog keeps it. What decides that is
 * `config/packages/monolog.yaml`, so this test drives a real authenticated request through the real handler
 * stack and reads the files Monolog wrote.
 *
 * The second half is the one with teeth. Making the line arrive by raising it past `action_level` would also
 * make it flush the whole `fingers_crossed` buffer — every record the request had accumulated, including
 * `ContextListener`'s `debug` line carrying the authenticated user's email. Asserting that the buffered log
 * did NOT grow is what pins the difference between "reported" and "reported at the price of a person's
 * address", and it goes red the moment the line is routed back to a buffered channel at a level that
 * activates it.
 *
 * @internal
 */
#[CoversNothing]
final class ActivityAuditWriteFailureArrivalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string FAILURE_MESSAGE = 'Failed to record an activity audit entry.';

    /**
     * `GET /api/v1/me` is audited at `activity` by the generic `kernel.terminate` hook: a business-semantic
     * `GET` that carries no `_audit_canonical`, so no explicit caller claims it first.
     */
    private const string AUDITED_ROUTE = '/api/v1/me';

    #[Test]
    public function aFailedActivityWriteArrivesWithoutFlushingTheBufferedHandler(): void
    {
        $client = self::createClient();

        // Registered before anything resolves the port — Symfony refuses to replace an already-initialized
        // service — and `disableReboot` keeps the double in the container the audited request resolves from.
        $writer = new ThrowingAuditLogWriter();
        self::getContainer()->set(AuditLogWriter::class, $writer);
        $client->disableReboot();

        $this->authenticateClient($client);

        // The suite shares logs nothing truncates, and other tests provoke 5xx that flush the same handler.
        // Only the tail each file gained during THIS request can be evidence, and the stat cache has to be
        // dropped before the offsets are taken as well as after — a stale-short offset widens the window that
        // is read and makes the growth assertion easier, not harder.
        $environment = self::getContainer()->getParameter('kernel.environment');
        $this->assertIsString($environment);
        $observability = $this->logFile('observability');
        $buffered = $this->logFile($environment);
        $observabilityBefore = $this->sizeOf($observability);
        $bufferedBefore = $this->sizeOf($buffered);

        $client->request(Request::METHOD_GET, self::AUDITED_ROUTE);

        // A non-2xx makes an absent line mean "never audited" rather than "discarded", twice over: the
        // listener yields on a response that is not successful, and Monolog excludes 404/405 from activation.
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            1,
            $writer->attempts,
            'the double actually threw — an arrival claim over a write that never happened proves nothing',
        );

        $appended = $this->tailOf($observability, $observabilityBefore);
        $this->assertStringContainsString(
            self::FAILURE_MESSAGE,
            $appended,
            'the swallowed audit-write failure reached the always-on log this request wrote',
        );
        // Read the level out of the bytes Monolog emitted, not off a double: the test-env observability
        // handler is JSON-formatted, so the record carries its own level and a demotion cannot hide here.
        $this->assertStringContainsString(
            '"level_name":"ERROR"',
            $appended,
            'the arriving record is the error it claims to be',
        );

        $this->assertSame(
            $bufferedBefore,
            $this->sizeOf($buffered),
            'the buffered handler never activated: reporting the loss must not flush a request buffer that '
            . "holds other components' records, one of which carries the authenticated user's email",
        );
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
