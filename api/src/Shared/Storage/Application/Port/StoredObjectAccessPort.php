<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application\Port;

/**
 * Aggregate view over all {@see StoredObjectReferenceInspector} implementations (Bank, Product, …).
 */
interface StoredObjectAccessPort
{
    public function existsAnyWithContentHash(string $contentHash): bool;

    public function getMimeTypeForContentHash(string $contentHash): ?string;
}
