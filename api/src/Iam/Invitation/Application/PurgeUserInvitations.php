<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Hard-deletes every invitation addressed to the user — the published seam a GDPR identity erasure consumes
 * so the delivery record that onboarded the person does not outlive them. `iam_invitation.invited_user_id`
 * is a plain id with no constraint behind it — nothing in the schema references `identity_user`, so
 * deleting the identity row cascades nowhere; and the terminal states
 * keep the id just as the pending ones do, because the lifecycle of an invitation has no bearing on whether
 * the column is personal data.
 *
 * It runs directed through the repository, so when the caller wraps it in a transaction — the erasure does —
 * the DELETE joins that unit of work and rolls back with it. It emits no event: the invitation's
 * observability is its domain events during its own lifecycle, and a subject being forgotten is not one of
 * them.
 */
final readonly class PurgeUserInvitations
{
    public function __construct(
        private InvitationRepository $invitations,
    ) {
    }

    public function purge(string $userId): int
    {
        Uuid::ensure($userId);

        return $this->invitations->deleteAllForInvitedUser($userId);
    }
}
