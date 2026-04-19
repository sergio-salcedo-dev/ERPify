<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Storage;

use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Storage\Application\Port\StoredObjectReferenceInspector;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('stored_object.reference_inspector', attributes: ['priority' => 0])]
final readonly class BankStoredObjectReferenceInspector implements StoredObjectReferenceInspector
{
    public function __construct(
        private BankRepository $bankRepository,
    ) {
    }

    #[\Override]
    public function countReferencesToContentHash(string $contentHash): int
    {
        return $this->bankRepository->countBanksWithStoredObjectContentHash($contentHash);
    }

    #[\Override]
    public function findMimeTypeForContentHash(string $contentHash): ?string
    {
        return $this->bankRepository->findStoredObjectMimeTypeByContentHash($contentHash);
    }
}
