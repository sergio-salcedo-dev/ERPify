<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use InvalidArgumentException;
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
                $resolved[$index] = $this->resolveLabel($type, $label);
            }

            return $resolved;
        }

        return $this->resolveLabel($type, $value);
    }

    /**
     * @param class-string<HumanReadableIntEnumInterface> $type
     */
    private function resolveLabel(string $type, mixed $label): mixed
    {
        if (\is_string($label)) {
            return $type::fromLabel($label) ?? $label;
        }

        // Only string labels can be looked up. A non-scalar label can never be an enum label, so
        // reject it here rather than letting it flow on and surface as an opaque TypeError
        // downstream. A non-string scalar carries no label to look up — pass it through unchanged.
        if (!\is_scalar($label)) {
            throw new InvalidArgumentException(\sprintf(
                'The "%s" enum hint requires string labels, got %s.',
                $type,
                \get_debug_type($label),
            ));
        }

        return $label;
    }
}
