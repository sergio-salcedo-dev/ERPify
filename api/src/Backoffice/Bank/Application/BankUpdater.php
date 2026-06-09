<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Command\UpdateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\InvalidUuidException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankUpdater
{
    public function __construct(
        private BankRepository $bankRepository,
        private BankFinder $bankFinder,
        private MessageBusInterface $messageBus,
        private Validator $validator,
    ) {
    }

    /**
     * @throws InvalidUuidException      when $id is not a well-formed UUID (400 invalid-input)
     * @throws BankNotFoundException
     * @throws ValidationFailedException when the updated entity fails validation (422)
     * @throws ExceptionInterface
     */
    public function update(string $id, UpdateBankCommand $bankCommand): Bank
    {
        $bank = $this->bankFinder->find($id);

        $bank->rename($bankCommand->name, $bankCommand->shortName);

        $this->validator->ensure($bank);

        $this->bankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
