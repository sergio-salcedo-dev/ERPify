<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCollectionSearchRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Search\Domain\Page;

/**
 * Read-side handler for the cross-bank account collection (`GET /bank-accounts`). It paginates the
 * global account read model and records the access through the {@see AuditLogger} seam — only on
 * success, even for an empty page (consulting the collection is an auditable access regardless of how
 * many rows match). Unlike the bank-scoped {@see BankAccountSearcher} there is no bank-existence guard
 * (the bank is a tunable filter, not a route constraint) and no {@see AuditResource} — the access is
 * collection-wide, not pinned to one aggregate. The call is direct: the best-effort boundary that keeps
 * an audit miss from sinking the read lives inside {@see AuditLogger}, so the searcher never wraps it in
 * a try/catch.
 */
final readonly class BankAccountCollectionSearcher
{
    private const string AUDIT_ACTION = 'BANK_ACCOUNTS_SEARCHED';

    public function __construct(
        private BankAccountCollectionSearchRepository $collectionSearchRepository,
        private AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return Page<BankAccountCollectionRow>
     */
    public function search(SearchBankAccountsQuery $query): Page
    {
        $page = $this->collectionSearchRepository->search($query->criteria);

        $this->auditLogger->log(self::AUDIT_ACTION, AuditLevel::ACTIVITY);

        return $page;
    }
}
