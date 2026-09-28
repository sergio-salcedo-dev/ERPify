<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use LogicException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Throwable;

/**
 * The one place a request-boundary listener records a `security` entry, and the one place the assumption that
 * makes such a row durable is checked rather than asserted.
 *
 * The audit writer owns no transaction: it inserts through the default DBAL {@see Connection}, so a `security`
 * row joins whatever transaction that connection has open. For a use case that is the point — a role change or
 * an erasure writes its row inside its own transaction so the row rolls back with the change it describes. For
 * a row recording a refusal at the HTTP boundary (a denial, a rejected current password, a read of the trail
 * itself) it is the opposite: the refusal happened whatever the business transaction does next, and a rollback
 * that took the row with it would lose a denial in silence. `wrapInTransaction` rolls back before it rethrows,
 * so by `kernel.exception` / `kernel.response` no transaction should be open; one that is was leaked.
 *
 * So the check runs BEFORE the write, on the same connection the writer uses: with no transaction active the
 * row is written in autocommit and is durable the moment the call returns; with one active it refuses with a
 * {@see LogicException} instead of writing a row it cannot prove will survive. A 5xx is the outcome the
 * `security` level already prefers to a silent loss, and the same reasoning makes a persistence failure
 * propagate untouched. It never rolls back a transaction it does not own and never opens a second connection —
 * either would hide the leak it exists to surface.
 *
 * Only for rows recording something at the request boundary. A use case writing `security` inside its own
 * transaction must keep calling {@see AuditLogger} directly; routed through here it would be refused.
 *
 * A caller on `kernel.exception` uses {@see recordOnException()}, never {@see record()}:
 * `HttpKernel::handleThrowable()` has no `try` around its listeners, so a throwable leaving one bypasses the
 * Problem Details pipeline entirely. That method hands the failure to the event instead, where the responder
 * renders it as the 5xx it is and logs it, and chains the request's own throwable into a refusal so the log
 * line still names what was being recorded. `BoundarySecurityAuditSeamGateTest` refuses a `kernel.exception`
 * listener calling {@see record()}.
 */
final readonly class RequestBoundarySecurityAudit
{
    public function __construct(
        private AuditLogger $auditLogger,
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @param Throwable|null       $cause    the throwable the entry records, chained into a refusal
     *
     * @throws LogicException when a transaction is open on the audit connection; nothing is written
     */
    public function record(string $action, array $metadata, ?Throwable $cause = null): void
    {
        if ($this->connection->isTransactionActive()) {
            // The action only: metadata may carry ids, and this message reaches the error log.
            throw new LogicException(\sprintf(
                'Refusing to record the "%s" security audit entry: a transaction is still open on the audit '
                . 'connection at the request boundary, so the row could be rolled back with it.',
                $action,
            ), 0, $cause);
        }

        $this->auditLogger->log($action, AuditLevel::SECURITY, metadata: $metadata);
    }

    /**
     * {@see record()} for a `kernel.exception` listener: a failed or refused write replaces the event's throwable
     * rather than escaping the kernel, and the refusal chains the throwable the entry was recording.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordOnException(ExceptionEvent $event, string $action, array $metadata): void
    {
        try {
            $this->record($action, $metadata, $event->getThrowable());
        } catch (Throwable $throwable) {
            $event->setThrowable($throwable);
        }
    }
}
