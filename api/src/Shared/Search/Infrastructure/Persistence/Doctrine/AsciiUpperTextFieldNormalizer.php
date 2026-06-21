<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Kernel\Domain\ValueObject\NormalizedText;
use Override;

/**
 * Normalizer for columns populated with {@see NormalizedText::toAsciiUpper()} (trim +
 * transliteration to upper-case ASCII), e.g. `bank.short_name`. The write side stores the
 * canonical upper-case form directly — the lower-casing {@see NormalizedTextFieldNormalizer}
 * would never match it — so searching with the same rule keeps eq/in/contains case- and
 * diacritic-insensitive by construction.
 */
final readonly class AsciiUpperTextFieldNormalizer implements FieldNormalizer
{
    #[Override]
    public function normalize(string $value): string
    {
        return NormalizedText::toAsciiUpper($value);
    }
}
