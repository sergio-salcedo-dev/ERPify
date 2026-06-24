<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankExistenceChecker;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountSearchRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;

/**
 * Read-side handler for the accounts of one bank. It guards the bank's existence first (so a
 * malformed/absent bank surfaces as 400/404 before any account work), paginates the accounts, and
 * records the access through the {@see AuditLogger} seam — only on success, even for an empty page
 * (consulting an existing bank's accounts is an auditable access regardless of how many accounts it
 * has). The call is direct: the best-effort boundary that keeps an audit miss from sinking the read
 * lives inside {@see AuditLogger}, so the searcher never wraps it in a try/catch.
 */
final readonly class BankAccountSearcher
{
    private const string AUDIT_ACTION = 'BANK_ACCOUNTS_VIEWED';

    private const string RESOURCE_TYPE = 'Bank';

    public function __construct(
        private BankExistenceChecker $bankExistence,
        private BankAccountSearchRepository $bankAccountSearchRepository,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * @throws InvalidUuidException  when $bankId is malformed (400 invalid-uuid, before any query)
     * @throws BankNotFoundException when no bank exists for $bankId (404)
     *
     * @return Page<BankAccount>
     */
    public function search(string $bankId, SearchBankAccountsQuery $query): Page
    {
        $this->bankExistence->ensureExists($bankId);

        $page = $this->bankAccountSearchRepository->search($bankId, $query->criteria);

        $this->auditLogger->log(
            self::AUDIT_ACTION,
            AuditLevel::ACTIVITY,
            AuditResource::of(self::RESOURCE_TYPE, $bankId),
        );

        return $page;
    }
}
