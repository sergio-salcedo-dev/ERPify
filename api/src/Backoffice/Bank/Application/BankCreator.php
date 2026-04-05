<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class BankCreator
{
    public function __construct(
        private readonly BankRepository $repository,
        private readonly MessageBusInterface $bus,
        private readonly MediaRegistrar $mediaRegistrar,
    ) {
    }

    public function create(string $name, string $shortName, ?UploadedFile $logoFile = null): Bank
    {
        $logo = $logoFile !== null ? $this->mediaRegistrar->registerFromUploadedFile($logoFile) : null;

        $bank = Bank::create(Uuid::v4(), $name, $shortName, $logo);

        $this->repository->save($bank);

        foreach ($bank->pullDomainEvents() as $event) {
            $this->bus->dispatch($event);
        }

        return $bank;
    }
}
