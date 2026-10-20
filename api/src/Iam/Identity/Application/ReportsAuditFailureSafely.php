<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Throwable;

/**
 * The shared tail of every `security`-level `*BestEffort` audit projection in this module: run a closure that
 * reports an already-swallowed write failure, and swallow whatever THAT raises too — mirroring
 * {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger::report()}'s own guard, "a catch whose entire
 * purpose is that nothing escapes may not throw". Without it, each consumer's `catch` block would call
 * `$this->logger->error(...)` unguarded, and a stream handler failing its own I/O (a closed stderr pipe, an
 * unwritable log directory) would escape the OUTER catch — it runs INSIDE it, not under
 * it — and abort whatever called `record()`: the scheduled tick for the two lockout rows (every remaining
 * locked identity in that run goes unreported), the login-failure handler for the throttle row
 * ({@see \Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler} catches only
 * `DbalException`, not `Throwable`, so it would not have stopped it either).
 *
 * The call to `$this->logger->error(...)` stays written at each consumer's own catch site, passed in as a
 * closure, rather than moving inside this trait:
 * {@see \Erpify\Tests\Unit\Gate\BestEffortReportChannelGateTest} derives which classes must bind the always-on
 * `observability` channel by scanning each FILE for the literal pair `catch (Throwable` + `$this->logger->` —
 * folding the call itself into this trait would drop every consumer out of that derivation, silently
 * removing them from a gate that exists precisely to stop a security-relevant report landing on a channel that
 * discards it, or worse, floods a person's email into the buffer it flushes.
 */
trait ReportsAuditFailureSafely
{
    /**
     * Where a swallowed projection failed, carried in the report's context under `phase`. One message per
     * class cannot say it on its own: the writers that serialise on the subject's row can fail on the lock wait
     * (`55P03` behind an erasure, a deadlock, BEGIN itself), on the INSERT, or on the COMMIT that follows it —
     * three different things for an operator to chase. `lock` covers everything before the write began,
     * including the commit of a transaction that found no subject and wrote nothing.
     */
    private const string PHASE_LOCK = 'lock';

    private const string PHASE_WRITE = 'write';

    private const string PHASE_COMMIT = 'commit';

    private function reportSafely(callable $report): void
    {
        try {
            $report();
        } catch (Throwable) {
            // The report is the last thing standing between a swallowed write and an escaped exception; it
            // may not become one itself. A stream whose sink has gone (a closed stderr pipe, an unwritable
            // log dir) takes the line with it, which is the same loss the outer catch already accepts.
        }
    }
}
