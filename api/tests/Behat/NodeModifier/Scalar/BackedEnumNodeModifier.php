<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\NodeModifier\Scalar;

use BackedEnum;
use Erpify\Tests\Behat\NodeModifier\AbstractNodeModifier;
use Override;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Resolves a `Fqcn::CASE` string into the matching BackedEnum case and compares it against an actual
 * BackedEnum instance or its backing scalar value.
 *
 * Resolves by value auto-detection (any string whose part before `::` is an enum) or the explicit
 * `field::BackedEnum` suffix. Nothing here requires the enum to be *named* with an `Enum` suffix —
 * none of this repo's are.
 *
 * Example (Gherkin):
 *   And the JSON node "role" should be equal to "Erpify\Shared\Access\Domain\Role::ADMIN"
 *   // matches either a Role instance or the scalar "ADMIN" in the response.
 */
class BackedEnumNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'BackedEnum';
    }

    #[Override]
    public function supportsValue(mixed $value): bool
    {
        return \is_string($value) && \enum_exists(\explode('::', $value)[0]);
    }

    #[Override]
    public function getProcessedValue(mixed $value): BackedEnum
    {
        if (!\is_string($value)) {
            throw new AssertionFailedError(\sprintf('Expected a "Fqcn::CASE" string, got %s', \get_debug_type($value)));
        }

        $parts = \explode('::', $value);

        // The case name is what follows the one `::` a "Fqcn::CASE" string carries. Locating it by
        // searching for the literal token `Enum::` instead made resolution depend on the enum being
        // *named* with that suffix: for `…\Enum\Role::ADMIN` the search finds nothing, the miss casts
        // to offset 0, and the case is read from six characters into the FQCN. No enum in this repo
        // carries the token, so every one of them resolved to a name no case matches.
        if (2 !== \count($parts)) {
            throw new AssertionFailedError(\sprintf('Expected exactly one "::" in "%s"', $value));
        }

        [$classString, $enumKey] = $parts;

        if (!\enum_exists($classString)) {
            throw new AssertionFailedError(\sprintf('Unknown enum "%s"', $classString));
        }

        if (!\is_subclass_of($classString, BackedEnum::class)) {
            throw new AssertionFailedError(\sprintf('Enum "%s" is not a BackedEnum', $classString));
        }

        $enum = $classString;

        foreach ($enum::cases() as $backedEnum) {
            if ($backedEnum->name === $enumKey) {
                return $backedEnum;
            }
        }

        throw new AssertionFailedError(\sprintf('Unknown value key "%s" for enum "%s"', $enumKey, $classString));
    }

    #[Override]
    public function compare(mixed $expected, mixed $value): bool
    {
        $backedEnum = $this->getProcessedValue($expected);

        if ($value instanceof BackedEnum) {
            return $backedEnum === $value;
        }

        return $backedEnum->value === $value;
    }
}
