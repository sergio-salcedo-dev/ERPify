<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Uuid;

use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * UUID identity for the domain. Built on `symfony/uid` under a documented layer
 * exception (see `docs/rules/architecture.md`): it is a leaf component with no
 * framework coupling, and is the best primitive for creating and validating
 * UUIDs across versions. Abstract base for UUID value objects: children extend
 * it and inherit `generate()`, the single static v7 mint.
 */
abstract class Uuid
{
    public static function generate(): string
    {
        return SymfonyUuid::v7()->toRfc4122();
    }
}
