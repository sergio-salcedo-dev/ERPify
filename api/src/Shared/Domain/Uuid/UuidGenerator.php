<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Uuid;

interface UuidGenerator
{
    public static function generate(): string;
}
