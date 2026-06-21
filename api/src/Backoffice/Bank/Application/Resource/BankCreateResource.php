<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of the create response (`POST /banks`). Distinct from the update view on purpose:
 * the create response carries the logo / stored-object URLs (a freshly uploaded image is echoed
 * back), but no `accountCount` (a new bank has no read-side count and emitting `0` would mix the
 * read model into the write path). URL fields are nullable so an absent image still serializes
 * `null`; timestamps are pre-formatted ATOM strings.
 */
final readonly class BankCreateResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
        public ?string $logoUrl,
        public ?string $storedObjectUrl,
    ) {
    }
}
