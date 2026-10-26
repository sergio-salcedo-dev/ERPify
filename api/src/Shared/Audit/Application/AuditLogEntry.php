<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditMetadataKey;
use Erpify\Shared\Audit\Domain\AuditProvenance;
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
 * What the act was — `action`, `level`, `resource`, `metadata` — is this class's; who did it, when and from
 * which client is the {@see AuditProvenance} it was sealed with. `metadata` and the provenance's `ip` and
 * `userAgent` are attacker-controlled: treat them as tainted downstream — never render them as HTML without
 * escaping, never use them in a trust or authorization decision.
 *
 * **`metadata` speaks a closed vocabulary, checked here.** Every key must be an {@see AuditMetadataKey} and every
 * value must have that key's shape, because no erasure pass reaches this column — both anonymisers rewrite
 * columns, never `metadata`, and crypto-shredding only matters to a value encrypted under the destroyed key —
 * so whatever enters it stays for the life of the row. This constructor is where every row the writer
 * persists is built, the change-capture listener's included, so it is the one place a key computed away from
 * its call site cannot slip past.
 *
 * **`metadata.changes` belongs to a `change` entry alone**, and so does its sibling `operation`. That key is
 * the field diff, and only the change-capture listener seals its personal values before they reach storage;
 * any other caller would write it in clear. Refusing it on every other level is also what lets the detail
 * route read the level as the diff's provenance.
 *
 * **And it is bounded**, as `action` and `user_agent` are by their column widths: {@see MAX_METADATA_BYTES} is
 * measured as JSON text with Unicode and slashes unescaped, so a non-Latin name costs its UTF-8 bytes rather
 * than the `\uXXXX` spelling the writer happens to send. Quotes, backslashes and control characters still count
 * at their escaped length, so the measure is an upper bound on what a `jsonb` value stores, never below it.
 */
final readonly class AuditLogEntry
{
    /**
     * Mirrors the `VARCHAR(100)` width of the `action` / `resource_type` columns, so an
     * over-long value fails here with an actionable error instead of in the Postgres INSERT.
     */
    private const int MAX_FIELD_LENGTH = 100;

    /** The `metadata` key holding the field diff, which only a `change` entry may carry. */
    public const string CHANGES_KEY = AuditMetadataKey::Changes->value;

    /**
     * Over twice the largest `change` entry a legitimate write can produce, which is the case that matters: a
     * refused `change` entry fails the write that produced it. That entry is a `Bank` update moving `name`,
     * `nameNormalized` and `shortName` at their column widths — 4,792 bytes with every character four UTF-8
     * bytes, about 6.9 KB if every character were one JSON escapes as `\u00XX` — and
     * `AuditLogEntryMetadataBoundTest` builds both and admits them. The bound refuses a producer that started
     * dumping a body or a list.
     */
    public const int MAX_METADATA_BYTES = 16_384;

    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public string $id,
        public string $action,
        public AuditLevel $level,
        public AuditProvenance $provenance,
        public ?AuditResource $resource,
        public array $metadata,
        public ?string $encryptionScopeId,
    ) {
    }

    /**
     * @param array<array-key, mixed> $metadata any keys at all: the ones outside the vocabulary are refused here
     */
    public static function create(
        string $action,
        AuditLevel $level,
        AuditProvenance $provenance,
        ?AuditResource $resource = null,
        array $metadata = [],
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

        $metadata = self::guardMetadata($level, $metadata);

        return new self(
            $id ?? Uuid::generate(),
            $action,
            $level,
            $provenance,
            $resource,
            $metadata,
            $encryptionScopeId,
        );
    }

    /**
     * The level rule runs before the shape rule, so a diff on the wrong level is refused for being there
     * whatever it holds.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, mixed> the same entries, now known to be keyed by the vocabulary
     */
    private static function guardMetadata(AuditLevel $level, array $metadata): array
    {
        $checked = [];

        foreach ($metadata as $name => $value) {
            $key = \is_string($name) ? AuditMetadataKey::tryFrom($name) : null;

            if (!$key instanceof AuditMetadataKey) {
                throw InvalidAuditLogEntry::unknownMetadataKey($name);
            }

            if (AuditLevel::CHANGE !== $level && $key->isReservedToChangeEntries()) {
                throw InvalidAuditLogEntry::reservedToChangeEntries($key, $level->value);
            }

            if (!$key->shape()->admits($value)) {
                throw InvalidAuditLogEntry::metadataValueOutOfShape($key);
            }

            $checked[$key->value] = $value;
        }

        $size = \strlen(\json_encode(
            (object) $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        if ($size > self::MAX_METADATA_BYTES) {
            throw InvalidAuditLogEntry::metadataExceedsMaxSize(self::MAX_METADATA_BYTES, $size);
        }

        return $checked;
    }

    private static function guardFieldLength(string $field, string $value): void
    {
        $length = \mb_strlen($value);

        if ($length > self::MAX_FIELD_LENGTH) {
            throw InvalidAuditLogEntry::fieldExceedsMaxLength($field, self::MAX_FIELD_LENGTH, $length);
        }
    }
}
