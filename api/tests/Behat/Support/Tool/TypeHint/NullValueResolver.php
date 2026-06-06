<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

use Override;

/**
 * Maps the literal `null` query-string token to a real `null`.
 */
final class NullValueResolver implements ValueResolverInterface
{
    #[Override]
    public function supports(mixed $value, ?string $type): bool
    {
        return 'null' === $value;
    }

    #[Override]
    public function resolve(mixed $value, ?string $type): mixed
    {
        return null;
    }
}
