<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

/**
 * One link in the query-string type-hint resolution chain: each resolver claims a value/type
 * pair via {@see supports()} and turns it into the typed value via {@see resolve()}.
 */
interface ValueResolverInterface
{
    public function supports(mixed $value, ?string $type): bool;

    public function resolve(mixed $value, ?string $type): mixed;
}
