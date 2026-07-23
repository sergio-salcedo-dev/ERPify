<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of the single-bank detail view (`GET /banks/{id}`). Carries the read-time
 * `accountCount` on top of the shared bank fields; timestamps are pre-formatted ATOM strings.
 */
final readonly class BankDetailResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
        public int $accountCount,
    ) {
    }
}
