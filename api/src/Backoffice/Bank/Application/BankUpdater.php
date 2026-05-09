<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\PostgresBankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class BankUpdater
{
    public function __construct(
        private PostgresBankRepository $postgresBankRepository,
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
    public function update(string $id, string $name, string $shortName): Bank
    {
        $bank = $this->bankFinder->find($id);

        $bank->rename(SymfonyUuidGenerator::generate(), $name, $shortName);

        // Re-run entity-level constraints after the in-place rename so `#[UniqueEntity]`
        // catches a name / shortName collision with another bank as a 400 violation rather
        // than letting Postgres raise a unique-constraint violation on save.
        $this->validator->ensure($bank);

        $this->postgresBankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
