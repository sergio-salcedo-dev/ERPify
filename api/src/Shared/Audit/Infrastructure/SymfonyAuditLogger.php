<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Application\AuditEntryFactory;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Production {@see AuditLogger}: routes a sealed entry by level over an asymmetric failure boundary.
 *
 * `security` is a synchronous write-before-send insert kept OUTSIDE the best-effort boundary — if
 * persisting a denial fails the exception propagates and the denied request does not silently succeed.
 * A non-auditable denial may surface as a 5xx, which is preferable to losing the record of a security
 * denial. `activity` is high-volume observability and IS best-effort: a build or dispatch hiccup is
 * swallowed and logged at warning, so a successful operation never becomes a 5xx over an audit miss.
 * The warning carries only `action`/`level`/`exception` — never metadata, actor or resource id, which
 * are tainted and could leak PII.
 *
 * Sealing the trusted context onto the entry is {@see AuditEntryFactory}'s job; this class only decides
 * where the sealed entry goes.
 */
#[AsAlias(AuditLogger::class)]
final readonly class SymfonyAuditLogger implements AuditLogger
{
    public function __construct(
        private MessageBusInterface $messageBus,
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
            AuditLevel::ACTIVITY => $this->dispatchActivity($action, $level, $resource, $metadata),
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
    private function dispatchActivity(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource,
        array $metadata,
    ): void {
        try {
            $this->messageBus->dispatch(
                new RecordAuditEntry($this->entryFactory->create($action, $level, $resource, $metadata)),
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to record an activity audit entry.', [
                'action' => $action,
                'level' => $level->value,
                'exception' => $throwable,
            ]);
        }
    }
}
