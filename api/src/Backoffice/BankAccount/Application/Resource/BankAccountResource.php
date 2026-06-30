<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Resource;

/**
 * Wire contract of the single-account views — create (`POST /bank-accounts`), detail
 * (`GET /bank-accounts/{id}`) and update (`PUT /bank-accounts/{id}`). Plain data: the serialized object
 * is this DTO, never the BankAccount entity. The three views are byte-identical, so a single resource
 * serves all of them (a deliberate deviation from Bank's four views, justified by Rule of Three —
 * the write/detail shapes do not diverge here). The owning `bankId` IS present (unlike the nested
 * list view, where it is the route); `bic` / `alias` are nullable; `currency` / `status` carry the
 * enum identity value; timestamps are pre-formatted ATOM strings.
 */
final readonly class BankAccountResource
{
    public function __construct(
        public string $id,
        public string $bankId,
        public string $holderName,
        public string $iban,
        public ?string $bic,
        public ?string $alias,
        public string $currency,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
