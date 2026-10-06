<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use DateInterval;
use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `identity_erasure_resweep.subject_id` via plain DBAL — a `DISTINCT` read
 * in bounded keyset pages ({@see KeysetDistinctIds}), never a mutation and never a hydration.
 *
 * **It lists only the rows that outlived their window, and that narrowing is the point rather than a gap.**
 * Every id in this column names an erased subject by construction: the row is written by the erasure itself,
 * after the identity is gone. Listed whole, the column would report each erasure as a divergence for the hour
 * its re-sweep is meant to run — a false alarm per erasure, which teaches an operator to ignore the control.
 * The defect this control exists for is a reference its owner failed to erase, and for this column that is a
 * row {@see ResweepErasedSubjects} should already have deleted. One extra window of slack keeps a tick that
 * is running at the moment of the read from being reported, and deriving both from the one constant keeps a
 * retuned window from making every row overdue.
 */
final readonly class DbalErasureResweepPersonReferences implements PersonReferenceSource
{
    private KeysetDistinctIds $ids;

    public function __construct(
        Connection $connection,
        private Clock $clock,
        int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE,
    ) {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(ErasureResweep::class . '::$subjectId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $window = new DateInterval(ErasureResweep::WINDOW);
        $overdueBefore = $this->clock->now()->sub($window)->sub($window);

        return $this->ids->idsOf(
            'identity_erasure_resweep',
            'subject_id',
            'created_at < :overdue_before',
            ['overdue_before' => $overdueBefore->format('Y-m-d H:i:s')],
        );
    }
}
