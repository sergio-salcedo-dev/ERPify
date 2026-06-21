<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Resource;

/**
 * Wire contract of the update response (`PUT /banks/{id}`). Deliberately the narrowest bank view:
 * no logo / stored-object URLs and no `accountCount`. Keeping the URL keys out (rather than emitting
 * `null`) is what distinguishes it from the create response and preserves the existing PUT contract.
 * Timestamps are pre-formatted ATOM strings.
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
