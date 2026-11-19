<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of one bank row in the collection view (`GET /banks`). Plain data: the serialized
 * object is this DTO, never the Bank entity, so the response shape is readable here without crossing
 * the aggregate or any serializer group. Timestamps are pre-formatted ATOM strings (the mapper owns
 * the format); `accountCount` is the read-time enrichment; `logoImageId` is the opaque id of the logo served by
 * `GET /images/{imageId}` (a presence flag and a cache key, never a URL), null when the bank has none.
 */
final readonly class BankListResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
        public int $accountCount,
        public ?string $logoImageId,
    ) {
    }
}
