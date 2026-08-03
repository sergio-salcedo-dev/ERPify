<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `membership.user_id` via plain DBAL — a `DISTINCT` read, never a
 * mutation and never a hydration.
 *
 * This context lists; it does not judge. Whether one of these ids is still a live person is knowledge of the
 * context that owns people, and asking it here would be the cross-context read the boundary forbids.
 *
 * The column is `UNIQUE`, so `DISTINCT` collapses nothing today — it is kept because the guarantee this
 * source owes its consumer is "each id once", not "each row once", and a uniqueness constraint on another
 * team's table is not something the contract should silently depend on. `ORDER BY` is what keeps two runs
 * over an unchanged table printing the same set in the same order, so an alert that diffs the reconciler's
 * output cannot fire on Postgres's choice of plan.
 */
final readonly class DbalMembershipPersonReferences implements PersonReferenceSource
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(Membership::class . '::$userId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM membership ORDER BY user_id',
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
