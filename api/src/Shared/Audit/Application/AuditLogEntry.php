<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRedaction;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Immutable record of one thing an actor did: the data the `audit_log` writer persists and
 * the investigation read model reads back. Construction is closed behind {@see create()},
 * which mints the v7 `id` — or adopts one the caller minted up front, so a value can be sealed
 * under the id before the row exists — the sole idempotency anchor, sealed downstream by the
 * `audit_log` primary key, and enforces the field invariants before the row can exist.
 *
 * `ip`, `userAgent` and `metadata` are attacker-controlled: treat them as tainted
 * downstream — never render them as HTML without escaping, never use them in a trust or
 * authorization decision. A captured `ip`/`userAgent` equal to {@see AuditRedaction::SENTINEL} is
 * rewritten on the way in, so the literal is written by an erasure alone; a row stored without that rewrite
 * may still hold a client-sent literal, and on any row the erasure flags are what attribute it (see
 * {@see AuditRedaction}).
 *
 * **`metadata.changes` belongs to a `change` entry alone.** That key is the field diff, and only the
 * change-capture listener seals its personal values before they reach storage; any other caller would write
 * it in clear, where no erasure pass reaches it — both anonymisers rewrite columns, never `metadata`, and
 * crypto-shredding only matters to a value encrypted under the destroyed key. Refusing it here, where every
 * row the writer persists is built, closes that path for every caller rather than for the one public logger,
 * and it is what lets the detail route read the level as the diff's provenance.
 */
final readonly class AuditLogEntry
{
    /**
     * Mirrors the `VARCHAR(100)` width of the `action` / `resource_type` columns, so an
     * over-long value fails here with an actionable error instead of in the Postgres INSERT.
     */
    private const int MAX_FIELD_LENGTH = 100;

    /** The `metadata` key holding the field diff, which only a `change` entry may carry. */
    public const string CHANGES_KEY = 'changes';

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
        ?string $id = null,
    ): self {
        $action = \trim($action);

        if ('' === $action) {
            throw InvalidAuditLogEntry::actionMustNotBeEmpty();
        }

        self::guardFieldLength('action', $action);

        if ($resource instanceof AuditResource) {
            self::guardFieldLength('resourceType', $resource->type);
        }

        if (AuditLevel::CHANGE !== $level && \array_key_exists(self::CHANGES_KEY, $metadata)) {
            throw InvalidAuditLogEntry::changesOnlyOnAChangeEntry($level->value);
        }

        return new self(
            $id ?? Uuid::generate(),
            $action,
            $level,
            $actor,
            $correlationId,
            $occurredOn,
            $resource,
            $metadata,
            AuditRedaction::neutraliseCaptured($ip),
            AuditRedaction::neutraliseCaptured($userAgent),
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
