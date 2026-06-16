<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use DateTime;
use InvalidArgumentException;
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
        // An absent value (null) is not malformed input: pass it through unchanged, mirroring how
        // the date node modifier treats null as "absent" rather than as garbage.
        if (null === $value) {
            return null;
        }

        // supports() claims the pair on the type alone, and this resolver terminates the chain.
        // A non-scalar value cannot become a DateTime, so reject it here with a clear message
        // rather than letting it flow on and surface as an opaque TypeError downstream.
        if (!\is_scalar($value)) {
            throw new InvalidArgumentException(\sprintf(
                'The "date" type hint requires a scalar value, got %s.',
                \get_debug_type($value),
            ));
        }

        return new DateTime((string) $value);
    }
}
