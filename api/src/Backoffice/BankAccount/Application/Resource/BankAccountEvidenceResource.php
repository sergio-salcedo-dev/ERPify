<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Resource;

/**
 * Wire contract of one piece of a bank account's evidence — an item of `GET /bank-accounts/{id}/evidence` and the body
 * of the attach's 201. `id` is the link; `documentId` is what the audited read route takes to download the original.
 * `name` is the opened original filename, null when it cannot or must not open (an erased document among them);
 * `state` is the document's lifecycle state, and only `ready` is downloadable. Never the scope, the uploader or the
 * sealed filename.
 */
final readonly class BankAccountEvidenceResource
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $kind,
        public ?string $name,
        public string $state,
        public string $digest,
        public int $sizeBytes,
        public string $attachedAt,
    ) {
    }
}
