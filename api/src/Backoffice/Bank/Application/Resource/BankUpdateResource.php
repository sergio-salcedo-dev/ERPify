<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of the update response (`PUT /banks/{id}`). Deliberately the narrowest bank view: no
 * `accountCount`, which is what distinguishes it from the detail response. Timestamps are
 * pre-formatted ATOM strings.
 */
final readonly class BankUpdateResource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
