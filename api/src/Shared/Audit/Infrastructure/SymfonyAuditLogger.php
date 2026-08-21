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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * **The report goes to the `observability` channel, and the channel is the decision — not the level.** Prod
 * routes the default channel through `fingers_crossed` at `action_level: error`, so a report below that is
 * discarded and a report at or above it ACTIVATES the buffer. Activation is not free and its cost is not ours
 * to pay: `handleBatch` emits every record the request had buffered, including other components' — Symfony's
 * `ContextListener` logs `'User was reloaded from a user provider.'` at `debug` with the user identifier, and
 * in this application that identifier is the person's email address. Reporting a lost audit row by flushing a
 * person's email into a sink with no rotation, no TTL and no declared owner of its erasure trades one
 * integrity defect for a worse one. `RedactionDenylist` cannot intervene: it strips by key, and the key
 * belongs to a vendor record.
 *
 * `observability` is the always-on stream this repository built for exactly this shape — a line that must
 * arrive on a successful response — and `monolog.yaml` excludes it from `fingers_crossed` by name. The report
 * therefore reaches stderr in prod without opening any buffer: one line, no flush, no amplification during the
 * outage it reports. It is not alone on that
 * channel: every class whose report must survive a non-5xx path binds it the same way, and
 * `BestEffortReportChannelGateTest` holds that population.
 *
 * `error` remains the level because a lost audit row is an integrity defect rather than a metric, but on this
 * channel the level no longer decides whether the line survives — which is the property worth having, since
 * the level is a token any edit can move and the channel is wired in configuration a gate can read.
 *
 * **Existing is not alerting, and this class stops at existing.** The Monolog→Sentry bridge is deliberately
 * unwired (config/packages/sentry.yaml) and the compose files bound that driver by size alone, so the line reaches
 * container stderr and nothing more.
 *
 * **What a prune of the trail costs is not what a lost row costs.** The CONTEXT carries only
 * `action`/`level`/`exception` — never metadata, actor or resource id, which are tainted and could leak PII.
 * That guarantee is about the context and not the whole record: the `Throwable` travels whole and a driver
 * quotes what it refused, so a malformed id can still reach the log inside the exception message, under no
 * key of ours.
 *
 * The report itself is wrapped, because a `catch` whose entire purpose is that nothing escapes may not throw:
 * a stream handler performs real I/O and raises on a failed write, which on the in-request capture path would
 * turn a successful operation into the 5xx this boundary exists to prevent.
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
        #[Autowire(service: 'monolog.logger.observability')]
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

    private function report(string $action, AuditLevel $level, Throwable $throwable): void
    {
        try {
            $this->logger->error('Failed to record an activity audit entry.', [
                'action' => $action,
                'level' => $level->value,
                'exception' => $throwable,
            ]);
        } catch (Throwable) {
            // The report is the last thing standing between a swallowed write and a 5xx; it may not become
            // one itself. A stream whose sink has gone (a closed stderr pipe, an unwritable log dir) takes
            // the line with it, which is the same loss this class already accepts one layer down.
        }
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
            $this->report($action, $level, $throwable);
        }
    }
}
