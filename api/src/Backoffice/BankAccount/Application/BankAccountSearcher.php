<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankExistenceChecker;
use Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountSearchRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Read-side handler for the accounts of one bank. It guards the bank's existence first (so a
 * malformed/absent bank surfaces as 400/404 before any account work), paginates the accounts, and
 * records an access-audit message — dispatched only on success, even for an empty page (consulting an
 * existing bank's accounts is an auditable access regardless of how many accounts it has).
 */
final readonly class BankAccountSearcher
{
    public function __construct(
        private BankExistenceChecker $bankExistence,
        private BankAccountSearchRepository $bankAccountSearchRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
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

        $this->recordAccess($bankId);

        return $page;
    }

    /**
     * Access auditing is observability, not part of the read contract: a dispatch/handler hiccup must
     * never turn an otherwise-successful read into a 5xx. The failure is itself logged (without any
     * account value) so the gap stays visible.
     */
    private function recordAccess(string $bankId): void
    {
        try {
            $this->messageBus->dispatch(BankAccountsViewedAuditEvent::record($bankId));
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Failed to record bank-accounts access audit.', [
                'bankId' => $bankId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
