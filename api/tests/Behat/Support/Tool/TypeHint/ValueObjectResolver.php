<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use Override;
use Throwable;

/**
 * Constructs a value object from a single-argument constructor, falling back to the raw value
 * when the type rejects the argument (the constructor throws).
 *
 * Terminating the chain here is equivalent to the pre-extraction fall-through to the `date`
 * branch: `'date'` is never a class, so both branches were mutually exclusive.
 */
final class ValueObjectResolver implements ValueResolverInterface
{
    #[Override]
    public function supports(mixed $value, ?string $type): bool
    {
        return \is_string($type) && \class_exists($type);
    }

    /**
     * @SuppressWarnings("PHPMD.EmptyCatchBlock")
     */
    #[Override]
    public function resolve(mixed $value, ?string $type): mixed
    {
        \assert(\is_string($type) && \class_exists($type));

        try {
            return new $type($value);
        } catch (Throwable) {
            // Type may not accept this constructor signature; fall back to the raw value.
            return $value;
        }
    }
}
