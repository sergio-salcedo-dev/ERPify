<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountIbanLookupRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;

/**
 * Exact-match read for one account by IBAN, over a POST body rather than the GET `filters[]`
 * vocabulary — see the IBAN wire contract in `adding-endpoints.md`: a value this sensitive never
 * belongs in a query string. Records the access through {@see AuditLogger} on BOTH outcomes — a
 * match and a miss — mirroring {@see BankAccountCollectionSearcher}'s "audit even an empty result"
 * rule: a miss is itself an auditable access (an IBAN someone tried and this org has no account
 * for), and leaving it unrecorded would let a `bankAccount.read`-only caller probe arbitrary IBANs
 * with no forensic trace of the misses. The IBAN itself never enters the audit metadata on either
 * path — a match keys its resource by the account's own id (mirroring {@see BankAccountSearcher}); a
 * miss carries no resource at all, since there is no account to key it by.
 */
final readonly class BankAccountIbanLookup
{
    private const string AUDIT_ACTION_FOUND = 'BANK_ACCOUNT_LOOKED_UP_BY_IBAN';

    private const string AUDIT_ACTION_MISSED = 'BANK_ACCOUNT_IBAN_LOOKUP_MISSED';

    private const string RESOURCE_TYPE = 'BankAccount';

    public function __construct(
        private BankAccountIbanLookupRepository $ibanLookupRepository,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws BankAccountNotFoundException when no account exists for $canonicalIban
     */
    public function lookup(string $canonicalIban): BankAccountCollectionRow
    {
        $row = $this->ibanLookupRepository->findByIban($canonicalIban);

        if (!$row instanceof BankAccountCollectionRow) {
            $this->auditLogger->log(self::AUDIT_ACTION_MISSED, AuditLevel::ACTIVITY);

            throw BankAccountNotFoundException::withIban();
        }

        $this->auditLogger->log(
            self::AUDIT_ACTION_FOUND,
            AuditLevel::ACTIVITY,
            AuditResource::of(self::RESOURCE_TYPE, $row->id),
        );

        return $row;
    }
}
