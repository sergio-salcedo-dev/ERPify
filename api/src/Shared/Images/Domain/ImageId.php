<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain;

use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The domain identity of an {@see Image}, a UUID v7 the module mints internally. No public method
 * accepts an {@see ImageId} to "create" one — only {@see generate()} mints, which is what makes the
 * id generation NFR (a caller can never inject its own id) true by construction.
 */
final readonly class ImageId
{
    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid::generate());
    }

    /**
     * @throws \Erpify\Shared\Uuid\Domain\InvalidUuidException when the value is not a well-formed UUID
     */
    public static function fromString(string $value): self
    {
        Uuid::ensure($value);

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
