<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class BankUpdater
{
    public function __construct(
        private readonly BankRepository $repository,
        private readonly BankFinder $finder,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function update(Uuid $id, string $name, string $shortName): Bank
    {
        $bank = $this->finder->find($id);

        $bank->rename($name, $shortName);

        $this->repository->save($bank);

        foreach ($bank->pullDomainEvents() as $event) {
            $this->bus->dispatch($event);
        }

        return $bank;
    }
}
