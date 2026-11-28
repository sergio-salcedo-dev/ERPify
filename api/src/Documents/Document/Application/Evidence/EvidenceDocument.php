<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use DateTimeImmutable;

/**
 * One document as a consumer's listing shows it: the opened original filename (null when it cannot or must not open),
 * the lifecycle state, and the digest and size the server computed at intake. Never the scope, the uploader or the
 * sealed filename.
 */
final readonly class EvidenceDocument
{
    public function __construct(
        public string $documentId,
        public ?string $name,
        public string $state,
        public string $digest,
        public int $sizeBytes,
        public DateTimeImmutable $filedAt,
    ) {
    }
}
