<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Override;

/**
 * Resolves a human-readable enum label (or list of labels) into its enum case, falling back to
 * the raw label when no case matches.
 */
final class EnumValueResolver implements ValueResolverInterface
{
    #[Override]
    public function supports(mixed $value, ?string $type): bool
    {
        return null !== $type && \is_a($type, HumanReadableIntEnumInterface::class, true);
    }

    #[Override]
    public function resolve(mixed $value, ?string $type): mixed
    {
        \assert(null !== $type && \is_a($type, HumanReadableIntEnumInterface::class, true));

        // Only string labels can be looked up; a non-string element (or whole value) is returned
        // unchanged so a malformed input surfaces downstream instead of aborting here.
        if (\is_array($value)) {
            $resolved = [];

            foreach ($value as $index => $label) {
                $resolved[$index] = \is_string($label) ? ($type::fromLabel($label) ?? $label) : $label;
            }

            return $resolved;
        }

        if (!\is_string($value)) {
            return $value;
        }

        return $type::fromLabel($value) ?? $value;
    }
}
