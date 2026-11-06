<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a successful evidence PUT: the document filed, its state, and the digest and size the server
 * computed and counted — which a caller compares with what it sent. Never the filename, which is sealed.
 */
final readonly class DocumentReceiptResource
{
    public function __construct(
        public string $id,
        public string $state,
        public string $digest,
        public int $sizeBytes,
    ) {
    }
}
