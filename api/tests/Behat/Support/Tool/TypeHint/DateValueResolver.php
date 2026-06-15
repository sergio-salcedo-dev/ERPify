<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use DateTime;
use Override;

/**
 * Resolves the `date` type hint into a mutable {@see DateTime}.
 */
final class DateValueResolver implements ValueResolverInterface
{
    #[Override]
    public function supports(mixed $value, ?string $type): bool
    {
        return 'date' === $type;
    }

    #[Override]
    public function resolve(mixed $value, ?string $type): mixed
    {
        // supports() matches on the type only, not the value: a non-scalar value cannot become
        // a DateTime, so pass it through unchanged for a later resolver/caller to handle.
        if (!\is_scalar($value)) {
            return $value;
        }

        return new DateTime((string) $value);
    }
}
