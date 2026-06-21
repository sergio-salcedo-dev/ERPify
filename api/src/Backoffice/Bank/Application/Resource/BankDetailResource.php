<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of the single-bank detail view (`GET /banks/{id}`). Carries the logo / stored-object
 * URLs and the read-time `accountCount`. The URL fields are typed nullable so the serializer keeps
 * emitting an explicit `null` when no image is attached (there is no `skip_null_values`); timestamps
 * are pre-formatted ATOM strings.
 */
final readonly class BankDetailResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
        public ?string $logoUrl,
        public ?string $storedObjectUrl,
        public int $accountCount,
    ) {
    }
}
