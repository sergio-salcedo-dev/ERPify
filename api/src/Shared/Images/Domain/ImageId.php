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
    private string $value;

    /**
     * **Lower-cased here, and nowhere else.** A UUID is case-insensitive as a VALUE and case-sensitive as
     * a STRING, and this identifier is read as both: the storage adapter derives its key by slicing the
     * spelling, while Postgres compares the `uuid` column by value. Left unnormalised, an id arriving in
     * upper case — {@see fromString()} accepts it, because `Uuid::isValid()` matches case-insensitively —
     * addresses a key nothing was ever written to while still selecting its row. On the deletion path that
     * is the failure this module is built to refuse: `delete()` reports a confirmed absence, the row goes,
     * and the bytes stay behind with nothing left referencing them, unreachable for ever because the module
     * keeps no bookkeeping that could find them again.
     *
     * The constructor is the only place that can make it true. Normalising inside the storage adapter would
     * fix the key and leave {@see equals()} still comparing spellings, and normalising at the queue seam
     * would leave every other caller of `fromString()` to remember.
     */
    private function __construct(string $value)
    {
        $this->value = \strtolower($value);
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
