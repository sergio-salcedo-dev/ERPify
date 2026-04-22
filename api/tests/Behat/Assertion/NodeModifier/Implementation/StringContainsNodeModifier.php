<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use JsonException;
use Override;

/**
 * Asserts the actual value *contains* the expected substring (case-insensitive). Arrays
 * are JSON-encoded first so nested payloads can be probed for a fragment without exact
 * structural equality.
 *
 * Example (Gherkin):
 *   And the JSON node "message" should be equal to "<contains>successfully created"
 *   And the JSON node "errors" should be equal to "<contains>must not be blank"
 */
class StringContainsNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'contains';
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function getProcessedValue(mixed $value): string
    {
        if (\is_array($value)) {
            return \json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (!\is_string($value)) {
            \assert(\is_scalar($value) || null === $value);

            return (string) $value;
        }

        return $value;
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        return false !== \stripos($this->getProcessedValue($value), $this->getProcessedValue($expected));
    }
}
