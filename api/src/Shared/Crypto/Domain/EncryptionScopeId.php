<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Domain;

use Erpify\Shared\Crypto\Domain\Exception\InvalidEncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Names the scope a data-encryption key protects and over which crypto-shredding operates — a
 * `"<TYPE>:<uuid>"` pair (`BANK_ACCOUNT:<uuid>` today). Cryptographic identity, deliberately decoupled
 * from domain identity: it presupposes no aggregate, so a future party aggregate can adopt the scope
 * without renaming the concept (ADR D13/D16).
 */
final readonly class EncryptionScopeId
{
    private const string SEPARATOR = ':';

    private function __construct(
        public string $type,
        public string $id,
    ) {
    }

    public static function forBankAccount(string $bankAccountId): self
    {
        return self::of('BANK_ACCOUNT', $bankAccountId);
    }

    public static function fromString(string $value): self
    {
        $parts = \explode(self::SEPARATOR, $value);

        if (2 !== \count($parts)) {
            throw InvalidEncryptionScopeId::malformed();
        }

        return self::of($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return $this->type . self::SEPARATOR . $this->id;
    }

    private static function of(string $type, string $id): self
    {
        if ('' === $type || !Uuid::isValid($id)) {
            throw InvalidEncryptionScopeId::malformed();
        }

        return new self($type, $id);
    }
}
