<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Production {@see AuditLogger}: seals the trusted context onto each record and branches on level.
 *
 * `security` is a synchronous write-before-send insert kept OUTSIDE the best-effort boundary — if
 * persisting a denial fails the exception propagates and the denied request does not silently succeed.
 * A non-auditable denial may surface as a 5xx, which is preferable to losing the record of a security
 * denial. `activity` is high-volume observability and IS best-effort: a dispatch or entry-build hiccup
 * is swallowed and logged at warning, so a successful operation never becomes a 5xx over an audit miss.
 * The warning carries only `action`/`level`/`exception` — never metadata, actor or resource id, which
 * are tainted and could leak PII.
 *
 * The actor is sealed here, in the request cycle, and travels serialized inside the entry — never
 * re-resolved in the worker, which runs off-request and would mislabel every activity row as system.
 *
 * The coupling is the irreducible cost of the seam: it composes the six write-side ports plus the
 * record and correlation types. Splitting it to satisfy the metric would invert the dependency it
 * exists to centralise.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(AuditLogger::class)]
final readonly class SymfonyAuditLogger implements AuditLogger
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private AuditLogWriter $writer,
        private ActorContextFactory $actorContextFactory,
        private Clock $clock,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        match ($level) {
            AuditLevel::SECURITY => $this->writer->write($this->buildEntry($action, $level, $resource, $metadata)),
            AuditLevel::ACTIVITY => $this->dispatchActivity($action, $level, $resource, $metadata),
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function dispatchActivity(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource,
        array $metadata,
    ): void {
        try {
            $this->messageBus->dispatch(new RecordAuditEntry($this->buildEntry($action, $level, $resource, $metadata)));
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to record an activity audit entry.', [
                'action' => $action,
                'level' => $level->value,
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function buildEntry(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource,
        array $metadata,
    ): AuditLogEntry {
        return AuditLogEntry::create(
            $level,
            $action,
            $this->actorContextFactory->current(),
            $this->resolveCorrelationId(),
            $this->clock->now(),
            $resource?->type,
            $resource?->id,
            $metadata,
        );
    }

    /**
     * The request's canonical correlation id when one is in flight; otherwise a fresh UUIDv7 so the
     * entry never carries a null correlation id. Off-request a system act is correlated only with itself.
     */
    private function resolveCorrelationId(): string
    {
        $candidate = $this->requestStack->getCurrentRequest()?->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        return \is_string($candidate) && CorrelationIdListener::isCanonical($candidate)
            ? $candidate
            : Uuid::generate();
    }
}
