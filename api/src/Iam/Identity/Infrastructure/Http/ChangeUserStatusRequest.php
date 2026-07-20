<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input payload for {@see \Erpify\Iam\Identity\Infrastructure\Controller\UserPatchStatusController}.
 *
 * Two complementary validation layers, not redundant: the enum type makes `#[StrictRequestPayload]` reject a
 * value outside {@see IdentityStatus} as a 422 at the HTTP boundary, and `#[Assert\Choice]` narrows the
 * ADMISSIBLE target to the two legal transitions the endpoint offers — `SUSPENDED` / `DEACTIVATED`. An
 * `INVITED` or `ACTIVE` target is a well-typed enum yet not a transition this endpoint performs, so it too
 * is a 422, refused before the domain is touched. The transition matrix FROM the current state stays on the
 * aggregate ({@see \Erpify\Iam\Identity\Domain\Entity\User::guardTransitionTo}); the DTO only bounds the target.
 */
final readonly class ChangeUserStatusRequest
{
    public function __construct(
        #[Assert\Choice(choices: [IdentityStatus::SUSPENDED, IdentityStatus::DEACTIVATED])]
        public IdentityStatus $status,
    ) {
    }
}
