<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Bus\Event\EventBus;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Storage\Application\Dto\StoredObjectWriteResult;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Erpify\Shared\Storage\Domain\StoredObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class BankCreator
{
    public function __construct(
        private BankRepository $bankRepository,
        private EventBus $eventBus,
        private MediaRegistrar $mediaRegistrar,
        private StoredImageObjectWriter $storedImageObjectWriter,
        private Validator $validator,
        private EntityManagerInterface $entityManager,
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

        // save + publish in one transaction so the aggregate, its domain_event rows and the outbox
        // commit atomically (closes the dual-write window). See docs/adr/event-driven-architecture.md.
        $this->entityManager->wrapInTransaction(function () use ($newBank): void {
            $this->bankRepository->save($newBank);
            $this->eventBus->publish(...$newBank->pullDomainEvents());
        });

        return $newBank;
    }
}
