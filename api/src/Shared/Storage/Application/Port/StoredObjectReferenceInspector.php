<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application\Port;

/**
 * One bounded context that persists a content hash pointing at {@see ContentAddressableObjectKey}.
 * Register implementations with tag <code>stored_object.reference_inspector</code> (optionally with priority).
 */
interface StoredObjectReferenceInspector
{
    public function countReferencesToContentHash(string $contentHash): int;

    public function findMimeTypeForContentHash(string $contentHash): ?string;
}
