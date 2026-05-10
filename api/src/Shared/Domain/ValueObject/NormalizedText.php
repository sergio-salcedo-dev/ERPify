<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\ValueObject;

/**
 * Pair of (display, normalized) representations of a human-readable text whose
 * uniqueness must ignore case and surrounding whitespace while still preserving
 * the casing the user typed for display purposes.
 *
 * Persist both halves: `display` for UI, `normalized` for unique indexes and
 * lookups. The normalization rule lives here so every entity that needs
 * case-insensitive uniqueness (Bank, Company, Customer, Supplier, …) shares it.
 */
final readonly class NormalizedText
{
    private function __construct(
        public string $display,
        public string $normalized,
    ) {
    }

    public static function from(string $raw): self
    {
        $display = \trim($raw);
        $normalized = \mb_strtolower($display);

        return new self($display, $normalized);
    }

    public static function normalize(string $raw): string
    {
        return \mb_strtolower(\trim($raw));
    }

    public function equals(self $other): bool
    {
        return $this->normalized === $other->normalized;
    }
}
