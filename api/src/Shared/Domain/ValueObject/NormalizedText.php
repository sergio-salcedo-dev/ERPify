<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\ValueObject;

use RuntimeException;
use Transliterator;

/**
 * Pair of (display, normalized) representations of a human-readable text whose
 * uniqueness must ignore case, surrounding whitespace, and diacritical marks
 * while still preserving the casing the user typed for display purposes.
 *
 * Persist both halves: `display` for UI, `normalized` for unique indexes and
 * lookups. The normalization rule lives here so every entity that needs
 * case- and accent-insensitive uniqueness (Bank, Company, Customer, Supplier,
 * …) shares it.
 */
final readonly class NormalizedText
{
    private const string TRANSLITERATOR_ID = 'Any-Latin; Latin-ASCII; Lower();';

    private function __construct(
        public string $display,
        public string $normalized,
    ) {
    }

    public static function from(string $raw): self
    {
        $display = \trim($raw);

        return new self($display, self::transliterate($display));
    }

    public static function normalize(string $raw): string
    {
        return self::transliterate(\trim($raw));
    }

    public function equals(self $other): bool
    {
        return $this->normalized === $other->normalized;
    }

    private static function transliterate(string $value): string
    {
        $transliterator = Transliterator::create(self::TRANSLITERATOR_ID);
        if (!$transliterator instanceof Transliterator) {
            throw new RuntimeException(\sprintf(
                'Failed to create Transliterator with id "%s"; ext-intl missing or ICU rules unavailable.',
                self::TRANSLITERATOR_ID,
            ));
        }

        $result = $transliterator->transliterate($value);
        if (false === $result) {
            throw new RuntimeException('Transliteration failed for the provided value.');
        }

        return $result;
    }
}
