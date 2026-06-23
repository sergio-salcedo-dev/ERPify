<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
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
        public AuditLevel $level,
        public string $action,
        public ActorContext $actor,
        public string $correlationId,
        public DateTimeImmutable $occurredOn,
        public ?string $resourceType,
        public ?string $resourceId,
        public array $metadata,
        public ?string $ip,
        public ?string $userAgent,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public static function create(
        AuditLevel $level,
        string $action,
        ActorContext $actor,
        string $correlationId,
        DateTimeImmutable $occurredOn,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        if ('' === \trim($action)) {
            throw InvalidAuditLogEntry::actionMustNotBeEmpty();
        }

        self::guardFieldLength('action', $action);

        if (null !== $resourceType) {
            self::guardFieldLength('resourceType', $resourceType);
        }

        return new self(
            Uuid::generate(),
            $level,
            $action,
            $actor,
            $correlationId,
            $occurredOn,
            $resourceType,
            $resourceId,
            $metadata,
            $ip,
            $userAgent,
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
