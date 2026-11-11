<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of the declare step. `id` is the upload to PUT the body to — null once the declare is answered
 * by a document whose intent has been retired — and `documentId` is set when an earlier upload with the same
 * idempotency key already filed one. `state` is `declared` for an upload waiting for its body and the document's
 * own state otherwise. `expiresAt` is when a waiting upload stops accepting its body.
 */
final readonly class UploadResource
{
    public function __construct(
        public ?string $id,
        public string $state,
        public ?string $documentId,
        public ?string $expiresAt,
    ) {
    }
}
