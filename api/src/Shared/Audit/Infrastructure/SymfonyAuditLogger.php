<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Application\AuditEntryFactory;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Production {@see AuditLogger}: writes a sealed entry by level over an asymmetric failure boundary.
 *
 * `security` is a write kept OUTSIDE the best-effort boundary — if persisting a denial fails the exception
 * propagates and the denied request does not silently succeed. A non-auditable denial may surface as a 5xx,
 * which is preferable to losing the record of a security denial. `activity` is high-volume observability and
 * IS best-effort: a build or write hiccup is swallowed and reported, so a successful operation never becomes
 * a 5xx over an audit miss.
 *
 * **The report is `error` and not `warning`, which is not a taste call.** Prod routes Monolog through
 * `fingers_crossed` with `action_level: error` (config/packages/monolog.yaml), which buffers everything below
 * that and discards it when no error follows. Nothing on an audited path follows: the generic hook audits only
 * successful `GET`s, and the modules that name their own action are reached from `GET` controllers too. Below
 * `error` the loss is therefore not best-effort but silent — the line exists in this file and in no production
 * log.
 *
 * Its cost is that same buffer. Raising the level OPENS it, so the flush emits what was buffered (up to
 * `buffer_size`, 50 in prod) and then — `stopBuffering` being Monolog's default — everything the request logs
 * afterwards goes out too. On the post-response hook almost nothing follows; on an in-request write the whole
 * remainder of the request does, so that ceiling is not a bound there. Doctrine's SQL is NOT part of it in
 * production (`dbal.logging` defaults to `%kernel.debug%`) but is in dev and test. And the line is unthrottled:
 * an outage confined to `audit_log` emits one per successful audited read, where the sibling `security`
 * projections in `Iam/Identity` spend a rate-limit budget before writing. All of it is paid deliberately so the
 * signal exists at all; the lever if that shape proves operationally unacceptable is the always-on
 * `observability` channel, which is already excluded from `fingers_crossed`.
 *
 * **Existing is not alerting, and this change stops at existing.** The Monolog→Sentry bridge is deliberately
 * unwired (config/packages/sentry.yaml) and no compose file declares a logging driver, so the line reaches
 * container stderr and nothing more. That is strictly more than a line no environment wrote, and strictly less
 * than something that pages anyone.
 *
 * The CONTEXT carries only `action`/`level`/`exception` — never metadata, actor or resource id, which are
 * tainted and could leak PII. That guarantee is about the context and not the whole record: the `Throwable`
 * travels whole and a driver quotes what it refused, so a malformed id can still reach the log inside the
 * exception message, under no key of ours.
 *
 * Both levels write synchronously in the request cycle. `activity` capture is hybrid: the generic hook runs on
 * `kernel.terminate`, after the response is sent, so it adds no client-visible latency, while a module naming
 * its own strong-semantic action writes in-request and pays that latency for a richer entry. Either way a
 * durable queue would only be a second PII-bearing copy of the row outside the audit-log retention/erasure
 * policies.
 *
 * `change` is not a caller-initiated level: a change row is captured atomically by the Doctrine `onFlush`
 * listener inside the writing transaction, so routing one through this logger is a programming error and is
 * rejected rather than written best-effort.
 *
 * Sealing the trusted context onto the entry is {@see AuditEntryFactory}'s job; this class only decides
 * where the sealed entry goes.
 */
#[AsAlias(AuditLogger::class)]
final readonly class SymfonyAuditLogger implements AuditLogger
{
    public function __construct(
        private AuditLogWriter $writer,
        private AuditEntryFactory $entryFactory,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        match ($level) {
            AuditLevel::SECURITY => $this->writeSecurity($action, $level, $resource, $metadata),
            AuditLevel::ACTIVITY => $this->writeActivity($action, $level, $resource, $metadata),
            AuditLevel::CHANGE => throw new LogicException(
                'A change-level entry is captured by the Doctrine onFlush listener, never routed through the '
                . 'audit logger.',
            ),
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeSecurity(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource,
        array $metadata,
    ): void {
        $this->writer->write($this->entryFactory->create($action, $level, $resource, $metadata));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeActivity(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource,
        array $metadata,
    ): void {
        try {
            $this->writer->write($this->entryFactory->create($action, $level, $resource, $metadata));
        } catch (Throwable $throwable) {
            $this->logger->error('Failed to record an activity audit entry.', [
                'action' => $action,
                'level' => $level->value,
                'exception' => $throwable,
            ]);
        }
    }
}
