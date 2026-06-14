<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Erpify\Backoffice\Bank\Domain\Exception\BankInUseException;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Domain\Clock\Clock;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BankDeleter
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
        private BankAccountRepository $bankAccountRepository,
        private MessageBusInterface $messageBus,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidUuidException  when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankNotFoundException
     * @throws BankInUseException
     * @throws ExceptionInterface
     */
    public function delete(string $id): void
    {
        $bank = $this->bankFinder->find($id);

        $accountCount = $this->bankAccountRepository->countByBankId($id);

        if ($accountCount > 0) {
            throw BankInUseException::withAccountCount($id, $accountCount);
        }

        $bank->delete($this->clock->now());

        // Pull events before removal so the aggregate is still intact when captured.
        $domainEvents = $bank->pullDomainEvents();

        try {
            $this->bankRepository->remove($bank);
        } catch (ForeignKeyConstraintViolationException) {
            // Belt-and-braces over the bank_account FK: the count check above gives the clean 409
            // in 99.99% of cases, but an account can be inserted in the TOCTOU window between that
            // count and this flush. The FK (NOT DEFERRABLE) then rejects the DELETE — without this
            // catch the client would get a 500 instead of the promised 409. The violation proves
            // there was >= 1 account at flush time; max(1, …) covers the reverse double-race where
            // the recount also lands on zero.
            $recount = $this->bankAccountRepository->countByBankId($id);

            throw BankInUseException::withAccountCount($id, \max(1, $recount));
        }

        foreach ($domainEvents as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }
    }
}
