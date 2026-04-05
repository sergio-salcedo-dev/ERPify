<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application;

use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use Erpify\Shared\Storage\Application\Port\StoredObjectReferenceInspector;
use Erpify\Shared\Storage\Domain\ContentAddressableObjectKey;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * After removing an entity that referenced a content hash, delete the blob if nothing else references it.
 */
final class StoredObjectOrphanCleaner
{
    /**
     * @param iterable<StoredObjectReferenceInspector> $inspectors
     */
    public function __construct(
        private readonly ObjectStoragePort $objectStorage,
        #[AutowireIterator('stored_object.reference_inspector')]
        private readonly iterable $inspectors,
    ) {
    }

    public function cleanupAfterRemoval(?string $contentHash): void
    {
        if ($contentHash === null || $contentHash === '') {
            return;
        }

        foreach ($this->inspectors as $inspector) {
            if ($inspector->countReferencesToContentHash($contentHash) > 0) {
                return;
            }
        }

        $this->objectStorage->delete(ContentAddressableObjectKey::fromContentHash($contentHash));
    }
}
