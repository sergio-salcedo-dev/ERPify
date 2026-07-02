<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain;

use Erpify\Backoffice\Identity\Domain\Exception\InvalidHashedPassword;
use SensitiveParameter;

/**
 * An already-computed password hash. The domain is opaque to the algorithm: it never references
 * bcrypt/argon2id nor any password hasher — hashing is an Infrastructure concern. The single invariant
 * is that a hash is never the empty string.
 */
final readonly class HashedPassword
{
    private function __construct(private string $hash)
    {
    }

    /**
     * @throws InvalidHashedPassword when `$hash` is the empty string
     */
    public static function fromHash(#[SensitiveParameter] string $hash): self
    {
        if ('' === $hash) {
            throw new InvalidHashedPassword();
        }

        return new self($hash);
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
