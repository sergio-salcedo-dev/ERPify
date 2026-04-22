<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Assertion\NodeModifier\Implementation;

use BackedEnum;
use Erpify\Tests\Behat\Assertion\NodeModifier\AbstractNodeModifier;
use Override;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Resolves a string like `App\Enum\StatusEnum::ACTIVE` into the matching BackedEnum case
 * and compares it against an actual BackedEnum instance or its backing scalar value.
 *
 * Example (Gherkin):
 *   And the JSON node "status" should be equal to "<BackedEnum>App\Enum\StatusEnum::ACTIVE"
 *   // matches either an ActiveEnum instance or the scalar "active" in the response.
 */
class BackedEnumNodeModifier extends AbstractNodeModifier
{
    #[Override]
    public function getModifier(): string
    {
        return 'BackedEnum';
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function support(string $path, mixed $value): bool
    {
        return \is_string($value) && \enum_exists(\explode('::', $value)[0]);
    }

    #[Override]
    public function getProcessedValue(mixed $value): BackedEnum
    {
        \assert(\is_string($value));
        $parts = \explode('::', $value);
        $classString = $parts[0];

        if (!\enum_exists($classString)) {
            throw new AssertionFailedError(\sprintf('Unknown enum "%s"', $classString));
        }

        if (!\is_subclass_of($classString, BackedEnum::class)) {
            throw new AssertionFailedError(\sprintf('Enum "%s" is not a BackedEnum', $classString));
        }

        $enum = $classString;
        $enumKey = \substr($value, ((int) \stripos($value, 'Enum::')) + 6);

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
