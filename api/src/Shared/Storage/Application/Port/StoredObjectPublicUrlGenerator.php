<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application\Port;

interface StoredObjectPublicUrlGenerator
{
    public function urlForContentHash(string $contentHash): string;
}
