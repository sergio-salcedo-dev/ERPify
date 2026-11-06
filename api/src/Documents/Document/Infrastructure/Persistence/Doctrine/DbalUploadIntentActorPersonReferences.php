<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `upload_intent.actor_id`, excluding the marker an erasure writes for the
 * reason {@see DbalDocumentUploaderPersonReferences} gives. An intent row an in-flight upload of an erased person
 * commits after the purge statement's snapshot is exactly what this source reports, for the re-sweep to take.
 */
final readonly class DbalUploadIntentActorPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(UploadIntent::class . '::$actorId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        return $this->ids->idsOf(
            'upload_intent',
            'actor_id',
            'actor_id <> CAST(:marker AS UUID)',
            ['marker' => ErasedPersonMarker::UUID],
        );
    }
}
