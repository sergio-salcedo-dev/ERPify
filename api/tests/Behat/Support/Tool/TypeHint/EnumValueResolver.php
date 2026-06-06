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

        if (\is_array($value)) {
            $resolved = [];

            foreach ($value as $index => $label) {
                \assert(\is_string($label));
                $resolved[$index] = $type::fromLabel($label) ?? $label;
            }

            return $resolved;
        }

        \assert(\is_string($value));

        return $type::fromLabel($value) ?? $value;
    }
}
