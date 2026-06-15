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

        $numeric = $this->toNumericString($value);

        return null === $numeric ? null : \bcmul($numeric, '1', self::SCALE);
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        $left = null === $value ? '0' : $this->toNumericString($value);
        $right = null === $expected ? '0' : $this->toNumericString($expected);

        // A non-numeric side can never compare equal to a numeric amount: fail the comparison
        // rather than feeding bccomp() a non-numeric string.
        if (null === $left || null === $right) {
            return false;
        }

        return 0 === \bccomp($left, $right, self::SCALE);
    }

    /**
     * @phpstan-return numeric-string|null
     */
    private function toNumericString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return \is_numeric($string) ? $string : null;
    }
}
