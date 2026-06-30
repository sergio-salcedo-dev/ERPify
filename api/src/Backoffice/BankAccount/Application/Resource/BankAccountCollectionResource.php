<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Resource;

/**
 * Wire contract of the cross-bank account collection view (`GET /bank-accounts`). Plain data: the
 * serialized object is this DTO, never the BankAccount entity nor the projection row. Each row carries
 * its owning bank's identity (`bankId` / `bankName` / `bankShortName`) composed by the read-side JOIN,
 * since the bank is not the route here; `bic` / `alias` are nullable so absent optionals serialize
 * `null`; `currency` / `status` carry the enum identity value, the mapper resolving `->value`. The
 * sortable keyset columns (`createdAt` / `updatedAt`) the projection exposes are deliberately dropped —
 * they are paging machinery, not part of the wire shape.
 */
final readonly class BankAccountCollectionResource
{
    public function __construct(
        public string $id,
        public string $bankId,
        public string $bankName,
        public string $bankShortName,
        public string $holderName,
        public string $iban,
        public ?string $bic,
        public ?string $alias,
        public string $currency,
        public string $status,
    ) {
    }
}
