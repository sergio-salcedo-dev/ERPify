<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `document_custody.actor_id`, excluding the marker an erasure writes for
 * the reason {@see DbalDocumentUploaderPersonReferences} gives. The actor index is what each page seeks through.
 */
final readonly class DbalDocumentCustodyActorPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(DocumentCustody::class . '::$actorId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return $this->ids->idsOf(
            'document_custody',
            'actor_id',
            'actor_id <> CAST(:marker AS UUID)',
            ['marker' => ErasedPersonMarker::UUID],
        );
    }
}
