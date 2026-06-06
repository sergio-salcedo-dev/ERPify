<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use DateTime;
use Override;

/**
 * Resolves the `date` type hint into a mutable {@see DateTime}, matching the pre-extraction behavior.
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
        \assert(\is_scalar($value));

        return new DateTime((string) $value);
    }
}
