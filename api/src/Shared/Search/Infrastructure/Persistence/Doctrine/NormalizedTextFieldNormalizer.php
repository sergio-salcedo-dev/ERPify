<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Domain\ValueObject\NormalizedText;
use Override;

/**
 * Normalizer for columns populated with {@see NormalizedText::normalize()} (trim + ICU
 * transliteration to lower-case ASCII), e.g. `bank.name_normalized`. Searching with the same
 * rule the write side used makes eq/in/contains case- and diacritic-insensitive by
 * construction.
 */
final readonly class NormalizedTextFieldNormalizer implements FieldNormalizer
{
    #[Override]
    public function normalize(string $value): string
    {
        return NormalizedText::normalize($value);
    }
}
