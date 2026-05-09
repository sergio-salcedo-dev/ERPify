<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\PostgresBankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BankCreator
{
    public function __construct(
        private PostgresBankRepository $postgresBankRepository,
        private MessageBusInterface $messageBus,
        private MediaRegistrar $mediaRegistrar,
        private StoredImageObjectWriter $storedImageObjectWriter,
        private Validator $validator,
    ) {
    }

    public function create(
        string $name,
        string $shortName,
        ?UploadedFile $logoFile = null,
        ?UploadedFile $storedObjectFile = null,
    ): Bank {
        $stored = $storedObjectFile instanceof UploadedFile
            ? $this->storedImageObjectWriter->storeFromUploadedFile($storedObjectFile, 'storedObject')
            : null;

        $logo = $logoFile instanceof UploadedFile
            ? $this->mediaRegistrar->registerFromUploadedFile($logoFile)
            : null;

        $bank = Bank::create(
            SymfonyUuidGenerator::generate(),
            SymfonyUuidGenerator::generate(),
            $name,
            $shortName,
            $logo,
            $stored?->objectKey,
            $stored?->mimeType,
            $stored?->byteSize,
            $stored?->contentHash,
        );

        $this->validator->ensure($bank);

        $this->postgresBankRepository->save($bank);

        foreach ($bank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $bank;
    }
}
