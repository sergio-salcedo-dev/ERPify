<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use DateTime;
use DateTimeInterface;
use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Normalises date-like values (strings, `now`, or `DateTimeInterface` instances) into a
 * formatted timestamp so the compared sides always share the same shape, ignoring seconds.
 *
 * Example (Gherkin):
 *   And the JSON node "createdAt" should be equal to "<date>now"
 *   And the JSON node "createdAt" should be equal to "<date>2026-04-23 10:00:00"
 */
class DateNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'date';
    }

    #[Override]
    public function support(string $path, mixed $value): bool
    {
        if (parent::support($path, $value)) {
            return true;
        }

        if ('now' === $value) {
            return true;
        }

        return \is_string($value) && false !== DateTime::createFromFormat('Y-m-d H:i:s', $value);
    }

    #[Override]
    public function getProcessedValue(mixed $value, string $format = DateTimeInterface::ATOM): ?string
    {
        if ('now' === $value) {
            $value = new DateTime();
        }

        if (null === $value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        \assert(\is_string($value));

        return (new DateTime($value))->format($format);
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        return $this->getProcessedValue($expected, 'Y-m-d H:i') === $this->getProcessedValue($value, 'Y-m-d H:i');
    }
}
