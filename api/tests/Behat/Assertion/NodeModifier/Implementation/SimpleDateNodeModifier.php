<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use DateTimeInterface;
use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Date-only variant of {@see DateNodeModifier}: normalises both sides to `Y-m-d` so the
 * time component is ignored. Use when only the calendar day matters.
 *
 * Example (Gherkin):
 *   And the JSON node "birthday" should be equal to "<simple_date>1990-01-15"
 */
class SimpleDateNodeModifier extends DateNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'simple_date';
    }

    #[Override]
    public function support(string $path, mixed $value): bool
    {
        return AbstractNodeModifier::support($path, $value);
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
