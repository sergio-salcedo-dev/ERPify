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
final readonly class StoredObjectOrphanCleaner
{
    /**
     * @param iterable<StoredObjectReferenceInspector> $inspectors
     */
    public function __construct(
        private ObjectStoragePort $objectStoragePort,
        #[AutowireIterator('stored_object.reference_inspector')]
        private iterable $inspectors,
    ) {
    }

    public function cleanupAfterRemoval(?string $contentHash): void
    {
        if (null === $contentHash || '' === $contentHash) {
            return;
        }

        foreach ($this->inspectors as $inspector) {
            if ($inspector->countReferencesToContentHash($contentHash) > 0) {
                return;
            }
        }

        $this->objectStoragePort->delete(ContentAddressableObjectKey::fromContentHash($contentHash));
    }
}
