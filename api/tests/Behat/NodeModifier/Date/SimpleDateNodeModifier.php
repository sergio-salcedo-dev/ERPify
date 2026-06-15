<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Date;

use DateTimeInterface;
use Override;

/**
 * Date-only variant of {@see DateNodeModifier}: normalises both sides to `Y-m-d` so the
 * time component is ignored. Use when only the calendar day matters.
 *
 * Example (Gherkin):
 *   And the JSON node "birthday::simple_date" should be equal to "1990-01-15"
 */
class SimpleDateNodeModifier extends DateNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'simple_date';
    }

    /**
     * Reachable only through the explicit `::simple_date` suffix: date-shaped values are claimed
     * by {@see DateNodeModifier} auto-detection, so the date-only variant never sniffs by value.
     */
    #[Override]
    public function supportsValue(mixed $value): bool
    {
        return false;
    }

    #[Override]
    public function getProcessedValue(mixed $value, string $format = DateTimeInterface::ATOM): ?string
    {
        return parent::getProcessedValue($value, 'Y-m-d');
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        return $this->getProcessedValue($expected, 'Y-m-d') === $this->getProcessedValue($value, 'Y-m-d');
    }
}
