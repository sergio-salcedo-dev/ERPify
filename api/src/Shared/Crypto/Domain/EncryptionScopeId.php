<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain;

use Erpify\Shared\Crypto\Domain\Exception\InvalidEncryptionScopeId;

/**
 * Names the scope a data-encryption key protects and over which crypto-shredding operates — a
 * `"<TYPE>:<id>"` pair (`BankAccount:<uuid>` today, the audited resource type verbatim). Cryptographic
 * identity, deliberately decoupled from domain identity: it presupposes no aggregate and no id shape (ADR
 * D13/D16). The one invariant it owns is separator-safety — neither part may contain the `:` — so the pair
 * maps to its string form injectively and round-trips through {@see fromString()}. Any id-shape rule an
 * audited resource must satisfy (today a UUID) is enforced at the audit boundary, not here.
 */
final readonly class EncryptionScopeId
{
    private const string SEPARATOR = ':';

    private function __construct(
        public string $type,
        public string $id,
    ) {
    }

    /**
     * The scope `type` is the audited resource type verbatim (e.g. `BankAccount`), so the sealer that
     * derives a scope from an aggregate and the erasure that targets it agree on one string without a
     * per-entity mapping.
     */
    public static function of(string $type, string $id): self
    {
        // A ':' in either part would break the injective (type, id) -> string mapping that fromString()
        // and the keystore primary key rely on; the id shape itself is deliberately not constrained.
        if (
            '' === $type || '' === $id
            || \str_contains($type, self::SEPARATOR)
            || \str_contains($id, self::SEPARATOR)
        ) {
            throw InvalidEncryptionScopeId::malformed();
        }

        return new self($type, $id);
    }

    public static function forBankAccount(string $bankAccountId): self
    {
        return self::of('BankAccount', $bankAccountId);
    }

    public static function fromString(string $value): self
    {
        $parts = \explode(self::SEPARATOR, $value, 2);

        if (2 !== \count($parts)) {
            throw InvalidEncryptionScopeId::malformed();
        }

        return self::of($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return $this->type . self::SEPARATOR . $this->id;
    }
}
