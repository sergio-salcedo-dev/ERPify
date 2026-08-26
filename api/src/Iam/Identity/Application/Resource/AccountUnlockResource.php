<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

use SensitiveParameter;

/**
 * Wire contract of the administrative account-unlock action (`POST /backoffice/users/{id}/unlock`). `unlocked`
 * surfaces {@see \Erpify\Iam\Identity\Domain\Entity\User::clearLockout()}'s own idempotency signal — `false`
 * on an identity that was already clear, `true` on a genuine recovery — so the console can tell the two apart
 * instead of a bare 200 that always claims a recovery happened.
 *
 * Deliberately narrower than {@see UserDetailResource}: the console already holds the row it acted on, and
 * this action does not change status or roles, so echoing them back would invite a caller to read this
 * response as a second source of truth for state this endpoint never touches.
 */
final readonly class AccountUnlockResource
{
    public function __construct(
        public string $id,
        #[SensitiveParameter]
        public string $email,
        public bool $unlocked,
    ) {
    }
}
