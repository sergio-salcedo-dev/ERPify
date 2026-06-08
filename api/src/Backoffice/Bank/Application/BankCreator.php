<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Storage\Application\Dto\StoredObjectWriteResult;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Erpify\Shared\Storage\Domain\StoredObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BankCreator
{
    public function __construct(
        private BankRepository $bankRepository,
        private MessageBusInterface $messageBus,
        private MediaRegistrar $mediaRegistrar,
        private StoredImageObjectWriter $storedImageObjectWriter,
        private Validator $validator,
    ) {
    }

    public function create(
        CreateBankCommand $bankCommand,
        ?UploadedFile $logoFile = null,
        ?UploadedFile $storedObjectFile = null,
    ): Bank {
        $stored = $storedObjectFile instanceof UploadedFile
            ? $this->storedImageObjectWriter->storeFromUploadedFile($storedObjectFile, 'storedObject')
            : null;

        $storedObject = $stored instanceof StoredObjectWriteResult
            ? new StoredObject($stored->objectKey, $stored->mimeType, $stored->byteSize, $stored->contentHash)
            : null;

        $logo = $logoFile instanceof UploadedFile
            ? $this->mediaRegistrar->registerFromUploadedFile($logoFile)
            : null;

        $newBank = Bank::create(
            Uuid::generate(),
            $bankCommand->name,
            $bankCommand->shortName,
            $logo,
            $storedObject,
        );

        $this->validator->ensure($newBank);

        $this->bankRepository->save($newBank);

        foreach ($newBank->pullDomainEvents() as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }

        return $newBank;
    }
}
