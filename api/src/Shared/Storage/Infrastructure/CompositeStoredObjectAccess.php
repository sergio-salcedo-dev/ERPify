<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Infrastructure;

use Erpify\Shared\Storage\Application\Port\StoredObjectAccessPort;
use Erpify\Shared\Storage\Application\Port\StoredObjectReferenceInspector;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsAlias(StoredObjectAccessPort::class)]
final readonly class CompositeStoredObjectAccess implements StoredObjectAccessPort
{
    /**
     * @param iterable<StoredObjectReferenceInspector> $inspectors
     */
    public function __construct(
        #[AutowireIterator('stored_object.reference_inspector')]
        private iterable $inspectors,
    ) {
    }

    #[Override]
    public function existsAnyWithContentHash(string $contentHash): bool
    {
        foreach ($this->inspectors as $inspector) {
            if ($inspector->countReferencesToContentHash($contentHash) > 0) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function getMimeTypeForContentHash(string $contentHash): ?string
    {
        foreach ($this->inspectors as $inspector) {
            $mime = $inspector->findMimeTypeForContentHash($contentHash);

            if (null !== $mime && '' !== $mime) {
                return $mime;
            }
        }

        return null;
    }
}
