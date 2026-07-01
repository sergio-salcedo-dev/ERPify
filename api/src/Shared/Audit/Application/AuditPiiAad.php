<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\Exception\InvalidAuditPiiAad;

/**
 * The audit-specific additional-authenticated-data bound to a sealed PII value: which field, which side of
 * the change ({@see PiiFieldPosition}), and which `audit_log` row. It pins a ciphertext to its exact slot,
 * so relocating it — to another field, the other side of the change, or another row of the same subject —
 * fails AEAD authentication and is thus detectable in an append-only trail an attacker can still write to.
 *
 * This is the single source of truth for the byte-exact AAD tail: the sealer builds it when writing and a
 * reader must rebuild it identically to decrypt, so any drift would surface as a silent decryption failure
 * indistinguishable from a real tamper. The encryption scope is bound separately by the encryptor and is
 * deliberately not repeated here — one owner per fact.
 */
final readonly class AuditPiiAad
{
    private const string SEPARATOR = '|';

    private function __construct(
        public string $field,
        public PiiFieldPosition $position,
        public string $auditLogId,
    ) {
    }

    public static function for(string $field, PiiFieldPosition $position, string $auditLogId): self
    {
        // The '|' join must stay injective (mirrors EncryptionScopeId's ':' invariant): a part carrying the
        // separator, or an empty part, would let two distinct slots map to one AAD.
        if (
            '' === $field || '' === $auditLogId
            || \str_contains($field, self::SEPARATOR)
            || \str_contains($auditLogId, self::SEPARATOR)
        ) {
            throw InvalidAuditPiiAad::malformed();
        }

        return new self($field, $position, $auditLogId);
    }

    public function toString(): string
    {
        return $this->field . self::SEPARATOR . $this->position->value . self::SEPARATOR . $this->auditLogId;
    }
}
