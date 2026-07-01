<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Immutable record of one thing an actor did: the data the `audit_log` writer persists and
 * the investigation read model reads back. Construction is closed behind {@see create()},
 * which mints the v7 `id` — the sole idempotency anchor, sealed downstream by the
 * `audit_log` primary key — and enforces the field invariants before the row can exist.
 *
 * `ip`, `userAgent` and `metadata` are attacker-controlled: treat them as tainted
 * downstream — never render them as HTML without escaping, never use them in a trust or
 * authorization decision.
 */
final readonly class AuditLogEntry
{
    /**
     * Mirrors the `VARCHAR(100)` width of the `action` / `resource_type` columns, so an
     * over-long value fails here with an actionable error instead of in the Postgres INSERT.
     */
    private const int MAX_FIELD_LENGTH = 100;

    /**
     * @param array<string, mixed> $metadata
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function __construct(
        public string $id,
        public string $action,
        public AuditLevel $level,
        public ActorContext $actor,
        public string $correlationId,
        public DateTimeImmutable $occurredOn,
        public ?AuditResource $resource,
        public array $metadata,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $encryptionScopeId,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public static function create(
        string $action,
        AuditLevel $level,
        ActorContext $actor,
        string $correlationId,
        DateTimeImmutable $occurredOn,
        ?AuditResource $resource = null,
        array $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $encryptionScopeId = null,
    ): self {
        $action = \trim($action);

        if ('' === $action) {
            throw InvalidAuditLogEntry::actionMustNotBeEmpty();
        }

        self::guardFieldLength('action', $action);

        if ($resource instanceof AuditResource) {
            self::guardFieldLength('resourceType', $resource->type);
        }

        return new self(
            Uuid::generate(),
            $action,
            $level,
            $actor,
            $correlationId,
            $occurredOn,
            $resource,
            $metadata,
            $ip,
            $userAgent,
            $encryptionScopeId,
        );
    }

    private static function guardFieldLength(string $field, string $value): void
    {
        $length = \mb_strlen($value);

        if ($length > self::MAX_FIELD_LENGTH) {
            throw InvalidAuditLogEntry::fieldExceedsMaxLength($field, self::MAX_FIELD_LENGTH, $length);
        }
    }
}
