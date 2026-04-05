<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Application\Port;

interface MediaPublicUrlGenerator
{
    /**
     * Absolute URL or path usable by the PWA as <img src> (cross-origin safe when absolute).
     */
    public function urlForContentHash(string $contentHash): string;
}
