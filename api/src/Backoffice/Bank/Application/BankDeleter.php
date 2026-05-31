<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Infrastructure\Persistence\PostgresBankRepository;
use Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BankDeleter
{
    public function __construct(
        private PostgresBankRepository $postgresBankRepository,
        private BankFinder $bankFinder,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function delete(string $id): void
    {
        $bank = $this->bankFinder->find($id);

        $bank->delete(SymfonyUuidGenerator::generate());

        // Pull events before removal so the aggregate is still intact when captured.
        $domainEvents = $bank->pullDomainEvents();

        $this->postgresBankRepository->remove($bank);

        foreach ($domainEvents as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }
    }
}
