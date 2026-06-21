<?php

declare(strict_types=1);

namespace Erpify\Shared\Clock\Domain;

use DateTimeImmutable;

/**
 * Domain port for reading the current instant. Keeps domain and application code free of any concrete
 * time source so behaviour can be exercised against a fixed point in tests; the production adapter
 * delegates to the Symfony Clock service.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
