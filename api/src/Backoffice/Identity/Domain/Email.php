<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain;

use Erpify\Backoffice\Identity\Domain\Exception\InvalidEmail;

/**
 * Canonical form of a user's email identifier: trimmed and lower-cased so the UNIQUE constraint and the
 * case-insensitive login lookup share one definition of "the same email". This value object owns only
 * that canonical shape; RFC format validity is enforced at the application boundary by `#[Assert\Email]`
 * on the aggregate (the framework validator never runs inside the domain).
 */
final readonly class Email
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidEmail when the value is blank
     */
    public static function from(string $raw): self
    {
        $canonical = \mb_strtolower(\trim($raw));

        if ('' === $canonical) {
            throw new InvalidEmail();
        }

        return new self($canonical);
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
