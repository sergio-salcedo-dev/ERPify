<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Override;

/**
 * In-memory {@see ActiveAdministratorDirectory} that IS the executable spec of the production adapter's
 * single-context `EXISTS` over `identity_user` (`status=ACTIVE AND ADMIN ∈ roles`). Each entry is an admin
 * identity keyed by its user id and valued by whether that user exists AND is `ACTIVE`. A phantom admin
 * (whose backing user was hard-deleted or is no longer `ACTIVE`) is present with `false` and deliberately
 * never counts, so it can never keep the last real administrator suspendable.
 *
 * @internal
 */
final readonly class InMemoryActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    /**
     * @param array<string, bool> $adminUserIsActive admin user id => (its backing User exists AND is ACTIVE)
     */
    public function __construct(private array $adminUserIsActive)
    {
    }

    #[Override]
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        return \array_any(
            $this->adminUserIsActive,
            static fn (bool $isActive, $adminUserId): bool => $isActive && $adminUserId !== $userId,
        );
    }
}
