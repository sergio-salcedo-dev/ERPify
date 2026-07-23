<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Override;

/**
 * In-memory {@see ActiveAdministratorDirectory} that IS the executable spec of the boolean question the
 * production adapter answers with a single-context containment read over `identity_user`
 * (`status=ACTIVE AND ADMIN ∈ roles`); the adapter additionally takes a `FOR UPDATE` row lock to serialize
 * concurrent transitions, out of scope for this in-memory spec. Each entry is an admin identity keyed by its
 * user id and valued by whether that user exists AND is `ACTIVE`. A phantom admin (whose backing user was
 * hard-deleted or is no longer `ACTIVE`) is present with `false` and deliberately never counts, so it can
 * never keep the last real administrator suspendable.
 *
 * Records the ids it was asked about, so a test can assert not only the verdict but whether the question was
 * put at all — the observable difference between a guard a use case always evaluates and one it evaluates only
 * when the change actually threatens the invariant.
 *
 * @internal
 */
final class InMemoryActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    /** @var list<string> */
    public array $askedWithout = [];

    /** @var list<string> */
    public array $askedWhetherAdministrator = [];

    /**
     * @param array<string, bool> $adminUserIsActive admin user id => (its backing User exists AND is ACTIVE)
     */
    public function __construct(private readonly array $adminUserIsActive)
    {
    }

    #[Override]
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        $this->askedWithout[] = $userId;

        return \array_any(
            $this->adminUserIsActive,
            static fn (bool $isActive, $adminUserId): bool => $isActive && $adminUserId !== $userId,
        );
    }

    /**
     * Membership of the map IS carrying the role: an entry valued `false` is an administrator whose backing
     * user is absent or no longer `ACTIVE`, and that identity still holds `ADMIN`. Which is exactly why the
     * production adapter's second query drops the status predicate the first one applies.
     */
    #[Override]
    public function holdsAdministratorRole(string $userId): bool
    {
        $this->askedWhetherAdministrator[] = $userId;

        return \array_key_exists($userId, $this->adminUserIsActive);
    }
}
