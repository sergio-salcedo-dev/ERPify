<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditLogWriter} on the append-only `audit_log` table via plain DBAL. The `id` is the v7 UUID the
 * entry minted, never a database value, so `ON CONFLICT (id) DO NOTHING` makes a redelivery of the **same**
 * message a silent no-op — at-least-once delivery never writes a second row nor aborts the caller's
 * transaction. There is deliberately no `UPDATE`/`DELETE` path: the log is immutable here (retention and
 * GDPR erasure are a separate concern).
 *
 * It owns no transaction and does not swallow database failures — the best-effort boundary that keeps an
 * audit failure from sinking a use case lives in the caller.
 */
#[AsAlias(AuditLogWriter::class)]
final readonly class DbalAuditLogWriter implements AuditLogWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_log '
            . '(id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, '
            . 'metadata, ip, user_agent, occurred_on) '
            . 'VALUES (CAST(:id AS UUID), :level, :action, :actor_type, CAST(:actor_id AS UUID), '
            . 'CAST(:correlation_id AS UUID), :resource_type, CAST(:resource_id AS UUID), '
            . 'CAST(:metadata AS JSONB), :ip, :user_agent, CAST(:occurred_on AS TIMESTAMPTZ)) '
            . 'ON CONFLICT (id) DO NOTHING',
            [
                'id' => $entry->id,
                'level' => $entry->level->value,
                'action' => $entry->action,
                'actor_type' => $entry->actor->type->value,
                'actor_id' => $entry->actor->actorId,
                'correlation_id' => $entry->correlationId,
                'resource_type' => $entry->resourceType,
                'resource_id' => $entry->resourceId,
                'metadata' => \json_encode($entry->metadata, JSON_THROW_ON_ERROR),
                'ip' => $entry->ip,
                'user_agent' => $entry->userAgent,
                'occurred_on' => $entry->occurredOn->format('Y-m-d H:i:s.uP'),
            ],
        );
    }
}
