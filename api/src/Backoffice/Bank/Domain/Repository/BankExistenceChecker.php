<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Repository;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

/**
 * Published existence guard for the bank aggregate, consumed by child-resource read endpoints
 * (e.g. accounts-by-bank) that must reject a missing or malformed bank before doing their own work.
 * It validates the id shape and presence without hydrating the {@see \Erpify\Backoffice\Bank\Domain\Entity\Bank}
 * — cheaper than a full find and reusable across contexts, so the "pre-read then read" pattern is not
 * re-implemented per endpoint.
 */
interface BankExistenceChecker
{
    /**
     * @throws InvalidUuidException  when $bankId is not a well-formed UUID (400 invalid-uuid, before any query)
     * @throws BankNotFoundException when no bank exists for the given $bankId (404)
     */
    public function ensureExists(string $bankId): void;
}
