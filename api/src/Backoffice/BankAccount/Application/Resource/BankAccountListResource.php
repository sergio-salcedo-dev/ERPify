<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Resource;

/**
 * Wire contract of the accounts-by-bank view (`GET /banks/{id}/accounts`). Plain data: the serialized
 * object is this DTO, never the BankAccount entity. The owning `bankId` is deliberately absent (it is
 * the route, not payload); `bic` / `alias` are nullable so absent optionals serialize `null`; `status`
 * carries the enum identity value (`ACTIVE` / `INACTIVE` / `CLOSED`), the mapper resolving `->value`.
 */
final readonly class BankAccountListResource
{
    public function __construct(
        public string $id,
        public string $holderName,
        public string $iban,
        public ?string $bic,
        public ?string $alias,
        public string $currency,
        public string $status,
    ) {
    }
}
