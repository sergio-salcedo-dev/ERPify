<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * The three ids a document is filed under: its own, the random id of its encryption scope and its uploader's. They are
 * validated together because the rules relate them — all lower-case UUIDs, the scope never the document's own id, and
 * the uploader never the erased-person marker, which only the erasure writes.
 */
final readonly class DocumentIdentifiers
{
    public function __construct(
        public string $id,
        public string $scopeId,
        public string $uploaderId,
    ) {
        foreach ([$id, $scopeId, $uploaderId] as $identifier) {
            Uuid::ensure($identifier);

            if (\strtolower($identifier) !== $identifier) {
                throw new InvalidArgumentException('Identifiers must be lower-case UUIDs.');
            }
        }

        if (ErasedPersonMarker::UUID === $uploaderId) {
            throw new InvalidArgumentException('The erased-person marker is written by the erasure, never filed.');
        }

        if ($scopeId === $id) {
            throw new InvalidArgumentException('The encryption scope id must not be the document id.');
        }
    }
}
