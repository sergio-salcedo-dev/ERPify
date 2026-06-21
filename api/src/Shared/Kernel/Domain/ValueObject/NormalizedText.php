<?php

declare(strict_types=1);

namespace Erpify\Shared\Kernel\Domain\ValueObject;

use LogicException;
use Transliterator;
use UnexpectedValueException;

/**
 * Pair of (display, normalized) representations of a human-readable text whose
 * uniqueness must ignore case, surrounding whitespace, and diacritical marks
 * while still preserving the casing the user typed for display purposes.
 *
 * Persist both halves: `display` for UI, `normalized` for unique indexes and
 * lookups. The normalization rule lives here so every entity that needs
 * case- and accent-insensitive uniqueness shares it.
 *
 * For codes whose canonical form is upper-case ASCII (BIC, ticker, short
 * code), call `toAsciiUpper()` directly: those are stored canonicalized
 * in a single column with a `UNIQUE` index — no display half needed.
 */
final readonly class NormalizedText
{
    private const string LOWER_RULE = 'Any-Latin; Latin-ASCII; Lower();';

    private const string UPPER_RULE = 'Any-Latin; Latin-ASCII; Upper();';

    private function __construct(
        public string $display,
        public string $normalized,
    ) {
    }

    public static function from(string $raw): self
    {
        $display = \trim($raw);

        return new self($display, self::transliterate($display, self::LOWER_RULE));
    }

    public static function normalize(string $raw): string
    {
        return self::transliterate(\trim($raw), self::LOWER_RULE);
    }

    /**
     * Canonical upper-case ASCII form for codes (tickers, BIC, short names)
     * whose only stored representation is the canonical one. Strips accents
     * and trims surrounding whitespace; "gle", "GLÉ", "  glé  " all collapse
     * to "GLE".
     */
    public static function toAsciiUpper(string $raw): string
    {
        return self::transliterate(\trim($raw), self::UPPER_RULE);
    }

    public function equals(self $other): bool
    {
        return $this->normalized === $other->normalized;
    }

    private static function transliterate(string $value, string $rule): string
    {
        // ext-intl + the ICU rule string are part of the deployed platform;
        // a null here means the build is broken (extension missing, ICU
        // unavailable) — surface it as a programmer/operator error, not as a
        // runtime condition that callers could meaningfully recover from.
        $transliterator = Transliterator::create($rule);

        if (!$transliterator instanceof Transliterator) {
            throw new LogicException(\sprintf(
                'Failed to create Transliterator with id "%s"; ext-intl missing or ICU rules unavailable.',
                $rule,
            ));
        }

        $result = $transliterator->transliterate($value);

        if (false === $result) {
            throw new UnexpectedValueException('Transliteration failed for the provided value.');
        }

        return $result;
    }
}
