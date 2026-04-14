<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class BankCreator
{
    public function __construct(
        private BankRepository $bankRepository,
        private MessageBusInterface $messageBus,
        private MediaRegistrar $mediaRegistrar,
        private StoredImageObjectWriter $storedImageObjectWriter,
    ) {
    }

    public function create(
        string $name,
        string $shortName,
        ?UploadedFile $logoFile = null,
        ?UploadedFile $storedObjectFile = null,
    ): Bank {
        $stored = $storedObjectFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            ? $this->storedImageObjectWriter->storeFromUploadedFile($storedObjectFile, 'stored_object')
            : null;

        $logo = $logoFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? $this->mediaRegistrar->registerFromUploadedFile($logoFile) : null;

        $bank = Bank::create(
            Uuid::v4(),
            $name,
            $shortName,
            $logo,
            $stored?->objectKey,
            $stored?->mimeType,
            $stored?->byteSize,
            $stored?->contentHash,
        );

        $this->bankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
