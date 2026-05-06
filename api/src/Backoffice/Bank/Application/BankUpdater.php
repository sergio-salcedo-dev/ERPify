<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\PostgresBankRepository;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankUpdater
{
    public function __construct(
        private PostgresBankRepository $postgresBankRepository,
        private BankFinder $bankFinder,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws BankNotFoundException
     * @throws ValidationFailedException
     * @throws ExceptionInterface
     */
    public function update(string $id, string $name, string $shortName): Bank
    {
        $bank = $this->bankFinder->find($id);

        $bank->rename($name, $shortName);

        $this->postgresBankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
