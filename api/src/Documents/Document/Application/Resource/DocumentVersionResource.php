<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of one version in a series page. `uploaderId` is null for an `Erased` version — a tombstone names no
 * person — and the nil UUID for a version whose uploader was erased: a marker, never a user. Never the filename.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class DocumentVersionResource
{
    public function __construct(
        public string $id,
        public ?string $predecessorId,
        public string $state,
        public string $digest,
        public int $sizeBytes,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?string $reviewAt,
        public ?string $uploaderId,
        public string $createdAt,
    ) {
    }
}
