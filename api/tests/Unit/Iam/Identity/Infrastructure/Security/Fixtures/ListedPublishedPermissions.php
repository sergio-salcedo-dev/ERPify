<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security\Fixtures;

use Erpify\Iam\Identity\Infrastructure\Security\PublishedPermissions;
use Override;

/**
 * A {@see PublishedPermissions} that publishes exactly the permissions it is handed, so a test can stand for a
 * catalog that has gained a resource the production one does not list.
 *
 * @internal
 */
final readonly class ListedPublishedPermissions implements PublishedPermissions
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(private array $permissions)
    {
    }

    #[Override]
    public function contains(string $candidate): bool
    {
        return \in_array($candidate, $this->permissions, true);
    }
}
