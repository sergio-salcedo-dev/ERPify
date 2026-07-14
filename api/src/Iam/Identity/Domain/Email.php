<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain;

use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;

/**
 * Canonical form of a user's email identifier: trimmed and lower-cased so the UNIQUE constraint and the
 * case-insensitive login lookup share one definition of "the same email". This value object owns only
 * that canonical shape; RFC format validity is enforced at the application boundary by `#[Assert\Email]`
 * on the aggregate (the framework validator never runs inside the domain).
 */
final readonly class Email
{
    /**
     * @param non-empty-string $value
     */
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

    /**
     * The lenient twin of {@see from()} for flows whose contract is silence, not an error: a blank value
     * yields null instead of an exception, so the caller folds it into its uniform outcome with no try/catch.
     */
    public static function tryFrom(string $raw): ?self
    {
        try {
            return self::from($raw);
        } catch (InvalidEmail) {
            return null;
        }
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
