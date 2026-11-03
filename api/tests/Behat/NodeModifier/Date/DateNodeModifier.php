<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Date;

use DateMalformedStringException;
use DateTime;
use DateTimeInterface;
use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;

/**
 * Normalises date-like values (strings, `now`, or `DateTimeInterface` instances) into an instant
 * and matches two of them when they lie less than {@see self::TOLERANCE_SECONDS} apart.
 *
 * A window, not a truncation: formatting both sides to the minute would answer on which side of a
 * minute boundary each instant fell, failing a row stamped at `13:02:59` and read at `13:03:00` one
 * second apart while matching two instants 57 seconds apart inside one minute.
 *
 * Example (Gherkin):
 *   And the JSON node "createdAt::date" should be equal to "now"
 *   And the JSON node "createdAt::date" should be equal to "2026-04-23 10:00:00"
 *   And the last inserted "Bank" entity should match:
 *     | createdAt::date | now |
 */
class DateNodeModifier extends AbstractNodeModifier
{
    /**
     * Covers a `"now"` compared against a value the application stamped during the same scenario: the
     * round trip and nothing more.
     */
    public const int TOLERANCE_SECONDS = 5;

    #[Override]
    public function getModifier(): string
    {
        return 'date';
    }

    #[Override]
    public function supportsValue(mixed $value): bool
    {
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

        // Only a string can be parsed into a DateTime here; a non-string actual is not a date,
        // so return null and let the comparison fail cleanly.
        if (!\is_string($value)) {
            return null;
        }

        // An unparseable string is not a date either: return null so the comparison fails cleanly,
        // rather than letting DateTime's constructor throw and abort the run far from the cause.
        try {
            return (new DateTime($value))->format($format);
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        // A null *input* means "absent"; two absent sides match. A null *result* from a non-null
        // input means "unparseable" and can never equal anything — so guard against treating two
        // unparseable sides as equal (the null === null false positive).
        if (null === $expected || null === $value) {
            return null === $expected && null === $value;
        }

        $processedExpected = $this->getProcessedValue($expected);
        $processedValue = $this->getProcessedValue($value);

        return null !== $processedExpected
            && null !== $processedValue
            && $this->matches($processedExpected, $processedValue);
    }

    /**
     * Receives both sides as {@see getProcessedValue()} rendered them, so a subclass that changes the
     * rendering changes what is compared here too.
     */
    protected function matches(string $expected, string $value): bool
    {
        $expectedAt = (new DateTime($expected))->getTimestamp();
        $valueAt = (new DateTime($value))->getTimestamp();

        return \abs($expectedAt - $valueAt) < self::TOLERANCE_SECONDS;
    }
}
