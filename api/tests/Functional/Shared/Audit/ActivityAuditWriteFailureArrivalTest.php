<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\Shared\Audit\Fixtures\ThrowingAuditLogWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The observable this change exists for: a failed `activity` write ARRIVES in the log, rather than being
 * recorded in a line no environment ever writes.
 *
 * A level assertion is deliberately NOT the gate here. `SymfonyAuditLoggerTest` already asserts the level
 * against an injected double, and that assertion was green for as long as the failure was unreportable in
 * production — it can only ever pin what the source calls, never whether Monolog keeps it. What decides that
 * is `config/packages/monolog.yaml`: `main` is `fingers_crossed` with `action_level: error`, which buffers
 * everything below and discards it when no error follows — and nothing on an audited path does, since
 * `activity` is captured on successful reads. This test therefore drives a real authenticated request through
 * the real handler stack and reads the file Monolog wrote.
 *
 * Its conclusion transfers to production because `when@test` and `when@prod` agree on the handler `type`, on
 * `action_level: error`, on the nested handler's `debug` level, and on leaving the default `app` channel — the
 * one `SymfonyAuditLogger` resolves — out of their exclusion lists. **That agreement is not gated today**, and
 * the two blocks already diverge on `channels` (`!event` here, `!deprecation` there): touch only `when@prod`
 * and this test stays green while production goes mute again.
 *
 * @internal
 */
#[CoversClass(SymfonyAuditLogger::class)]
final class ActivityAuditWriteFailureArrivalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string FAILURE_MESSAGE = 'Failed to record an activity audit entry.';

    /**
     * `GET /api/v1/me` is audited at `activity` by the generic `kernel.terminate` hook: a business-semantic
     * `GET` that carries no `_audit_canonical`, so no explicit caller claims it first.
     */
    private const string AUDITED_ROUTE = '/api/v1/me';

    public function testAFailedActivityWriteReachesTheLogInsteadOfBeingBufferedAway(): void
    {
        $client = self::createClient();

        // Registered before anything resolves the port — Symfony refuses to replace an already-initialized
        // service — and `disableReboot` keeps the double in the container the audited request resolves from.
        $writer = new ThrowingAuditLogWriter();
        self::getContainer()->set(AuditLogWriter::class, $writer);
        $client->disableReboot();

        $this->authenticateClient($client);

        // The suite shares one never-truncated `test.log`, and other tests provoke 5xx that flush the same
        // handler. Only the tail appended by THIS request can be evidence.
        $logFile = $this->logFile();
        $offsetBefore = \is_file($logFile) ? (int) \filesize($logFile) : 0;

        $client->request(Request::METHOD_GET, self::AUDITED_ROUTE);

        // A non-2xx makes an absent line mean "never audited" rather than "discarded", twice over: the
        // listener yields on a response that is not successful, and Monolog excludes 404/405 from activation.
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            1,
            $writer->attempts,
            'the double actually threw — an arrival claim over a write that never happened proves nothing',
        );

        \clearstatcache(true, $logFile);
        $this->assertFileExists($logFile, 'the buffer was flushed, so the nested stream handler created its file');
        $this->assertGreaterThan(
            $offsetBefore,
            (int) \filesize($logFile),
            'the request appended to the log; without growth there is nothing to search',
        );

        $appended = \file_get_contents($logFile, offset: $offsetBefore);
        $this->assertIsString($appended);
        $this->assertStringContainsString(
            self::FAILURE_MESSAGE,
            $appended,
            'the swallowed audit-write failure reached the log this request wrote',
        );
    }

    private function logFile(): string
    {
        $container = self::getContainer();

        $logsDir = $container->getParameter('kernel.logs_dir');
        $this->assertIsString($logsDir);

        $environment = $container->getParameter('kernel.environment');
        $this->assertIsString($environment);

        return $logsDir . '/' . $environment . '.log';
    }
}
