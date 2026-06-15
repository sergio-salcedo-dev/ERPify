<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Identifier;

use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;
use Stringable;

/**
 * Asserts a value is a well-formed RFC 4122 UUID, so generated identifiers can be matched
 * without hand-rolling a 36-char regex in every scenario. The expected side selects the
 * required version: `v7` / `v4` (any single version digit), or `any` / `uuid` / `true` for
 * "any valid UUID".
 *
 * Example (Gherkin):
 *   And the JSON node "data.id::uuid" should be equal to "v7"
 *   And the last inserted "Bank" entity found by "shortName=TB" should match:
 *     | id::uuid | v7 |
 */
class UuidNodeModifier extends AbstractNodeModifier
{
    private const string RFC_4122 = '/^[0-9a-f]{8}-[0-9a-f]{4}-([1-8])[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** @var list<string> */
    private const array ANY_VERSION_TOKENS = ['', 'any', 'uuid', 'true'];

    #[Override]
    public function getModifier(): string
    {
        return 'uuid';
    }

    #[Override]
    public function getProcessedValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        \assert(\is_scalar($value) || $value instanceof Stringable);

        return (string) $value;
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        $actual = $this->getProcessedValue($value);

        if (null === $actual || 1 !== \preg_match(self::RFC_4122, $actual, $matches)) {
            return false;
        }

        $requiredVersion = $this->requiredVersion($expected);

        return null === $requiredVersion || (int) $matches[1] === $requiredVersion;
    }

    private function requiredVersion(mixed $expected): ?int
    {
        if (!\is_string($expected)) {
            return null;
        }

        $expected = \strtolower(\trim($expected));

        if (\in_array($expected, self::ANY_VERSION_TOKENS, true)) {
            return null;
        }

        if (1 === \preg_match('/^v?([1-8])$/', $expected, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
