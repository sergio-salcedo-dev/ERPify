<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of one item of the document listing (`GET /documents`). `name` is the opened original filename, or
 * null when there is none to give (erased, destroyed key, unreadable). Never the uploader, the scope or the
 * ciphertext. Timestamps are ATOM strings, pre-formatted by the mapper.
 */
final readonly class DocumentListResource
{
    public function __construct(
        public string $id,
        public string $seriesId,
        public ?string $predecessorId,
        public string $state,
        public string $digest,
        public int $sizeBytes,
        public ?string $name,
        public ?string $retentionReason,
        public ?string $legalBasis,
        public ?string $reviewAt,
        public string $createdAt,
    ) {
    }
}
