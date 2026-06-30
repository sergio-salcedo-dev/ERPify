<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Projection;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Enum\Currency;

/**
 * One row of the cross-bank account collection — a read-only projection built by a DQL
 * `SELECT NEW` over `BankAccount` JOINed to its owning `Bank` (the read-composition pattern ADR D3
 * prescribes for "account + bank name"). It is a query result, never the persisted aggregate; it
 * lives in `Domain/` because the read port returns it, mirroring the sibling
 * {@see \Erpify\Backoffice\Audit\Domain\AuditTimelineEntry}.
 *
 * It carries the wire fields PLUS the sortable keyset columns (`createdAt`/`updatedAt`) the engine's
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor}
 * reads off each result row by bare property path — so they are public readonly properties even
 * though the wire resource drops them. Doctrine applies field-type conversion and `enumType`
 * resolution to `SELECT NEW` constructor arguments, so `currency`/`status` hydrate as their enums and
 * `createdAt`/`updatedAt` as `DateTimeImmutable`; the resource mapper resolves the enums to their
 * `->value` on the wire.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class BankAccountCollectionRow
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
        public Currency $currency,
        public BankAccountStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
