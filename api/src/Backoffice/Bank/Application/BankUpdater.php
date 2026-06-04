<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Http\BankPutPayload;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
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
     * @throws BankNotFoundException
     * @throws ValidationFailedException
     * @throws ExceptionInterface
     */
    public function update(string $id, BankPutPayload $payload): Bank
    {
        $bank = $this->bankFinder->find($id);

        $bank->rename($payload->name, $payload->shortName);

        $this->validator->ensure($bank);

        $this->bankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
