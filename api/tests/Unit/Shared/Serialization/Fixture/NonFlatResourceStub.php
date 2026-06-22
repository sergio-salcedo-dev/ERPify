<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Serialization\Fixture;

use DateTimeImmutable;

/**
 * Negative control for {@see \Erpify\Tests\Unit\Shared\Serialization\ResourceDtoContractTest}: a
 * deliberately NON-flat resource-shaped DTO carrying a raw {@see DateTimeImmutable}. The contract
 * check must reject it, proving the production sweep actually bites and is not a vacuous pass.
 *
 * It lives outside any `Application/Resource/` directory so the production sweep never discovers it.
 *
 * @internal
 */
final readonly class NonFlatResourceStub
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
