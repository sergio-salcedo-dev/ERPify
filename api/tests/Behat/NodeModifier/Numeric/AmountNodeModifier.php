<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Numeric;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Compares decimal amounts at 18-digit precision via bcmath so values like "1.0" and "1.000000000000000000"
 * are treated as equal regardless of how they were stored or serialized.
 *
 * Example (Gherkin):
 *   And the JSON node "balance::amount" should be equal to "1.5"
 */
class AmountNodeModifier extends AbstractNodeModifier
{
    private const int SCALE = 18;

    #[Override]
    public function getModifier(): string
    {
        return 'amount';
    }

    #[Override]
    public function getProcessedValue(mixed $value): ?string
    {
        if (null === $value || 'null' === $value) {
            return null;
        }

        return \bcmul($this->toNumericString($value), '1', self::SCALE);
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        $left = null === $value ? '0' : $this->toNumericString($value);
        $right = null === $expected ? '0' : $this->toNumericString($expected);

        return 0 === \bccomp($left, $right, self::SCALE);
    }

    /**
     * @phpstan-return numeric-string
     */
    private function toNumericString(mixed $value): string
    {
        \assert(\is_scalar($value));
        $string = (string) $value;
        \assert(\is_numeric($string));

        return $string;
    }
}
