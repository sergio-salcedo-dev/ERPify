<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Media\Application\MediaRegistrar;
use Erpify\Shared\Storage\Application\Dto\StoredObjectWriteResult;
use Erpify\Shared\Storage\Application\StoredImageObjectWriter;
use Erpify\Shared\Storage\Domain\StoredObject;

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
        ?UploadedImage $logo = null,
        ?UploadedImage $storedObject = null,
    ): Bank {
        $stored = $storedObject instanceof UploadedImage
            ? $this->storedImageObjectWriter->store($storedObject, 'storedObject')
            : null;

        $bankStoredObject = $stored instanceof StoredObjectWriteResult
            ? new StoredObject($stored->objectKey, $stored->mimeType, $stored->byteSize, $stored->contentHash)
            : null;

        $logoMedia = $logo instanceof UploadedImage
            ? $this->mediaRegistrar->register($logo)
            : null;

        $newBank = Bank::create(
            Uuid::generate(),
            $bankCommand->name,
            $bankCommand->shortName,
            $logoMedia,
            $bankStoredObject,
        );

        $this->validator->ensure($newBank);

        // save + publish in one transaction so the aggregate, its event_store rows and the outbox
        // commit atomically (closes the dual-write window). See docs/adr/event-store-and-projections.md.
        $this->entityManager->wrapInTransaction(function () use ($newBank): void {
            $this->bankRepository->save($newBank);
            $this->eventBus->publish(...$newBank->pullDomainEvents());
        });

        return $newBank;
    }
}
