<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `document.uploader_id`: a `DISTINCT` read in bounded keyset pages, never a
 * mutation and never a hydration.
 *
 * The marker an erasure writes is excluded. It names nobody, so it is not a reference to a person, and
 * listing it would make every correctly erased uploader look like a retained id that resolves to no live
 * identity — a divergence reported for ever, which buries a real one.
 */
final readonly class DbalDocumentUploaderPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(Document::class . '::$uploaderId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return $this->ids->idsOf(
            'document',
            'uploader_id',
            'uploader_id <> CAST(:marker AS UUID)',
            ['marker' => ErasedPersonMarker::UUID],
        );
    }
}
