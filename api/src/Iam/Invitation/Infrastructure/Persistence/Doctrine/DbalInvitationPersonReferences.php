<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Shared\Privacy\Domain\PersonReferenceAxis;
use Override;

/**
 * {@link PersonReferenceSource} over `iam_invitation.invited_user_id` via plain DBAL — a `DISTINCT` read,
 * never a mutation and never a hydration.
 *
 * Ids only, and that is a confidentiality constraint rather than a convenience: an invitation row carries
 * the at-rest `token_hash`, a credential digest that must reach neither an operator's console nor whatever
 * a monitoring check does with this output.
 *
 * `DISTINCT` is load-bearing here — a person can be invited more than once, so without it one subject is
 * reported once per invitation addressed to them. `invited_user_id` is indexed, so the scan stays indexed
 * as the table grows; `ORDER BY` keeps the set stable across runs so a diffing alert cannot fire on noise.
 */
final readonly class DbalInvitationPersonReferences implements PersonReferenceSource
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function axis(): PersonReferenceAxis
    {
        return PersonReferenceAxis::of(Invitation::class . '::$invitedUserId');
    }

    #[Override]
    public function retainedPersonIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT invited_user_id FROM iam_invitation ORDER BY invited_user_id',
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
