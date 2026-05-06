<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Uuid;

use Erpify\Shared\Domain\Uuid\UuidGenerator;
use Override;
use Symfony\Component\Uid\Uuid;

final class SymfonyUuidGenerator implements UuidGenerator
{
    #[Override]
    public static function generate(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
