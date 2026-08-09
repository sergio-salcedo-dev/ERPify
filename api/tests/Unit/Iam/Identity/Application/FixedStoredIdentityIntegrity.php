<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Override;

/**
 * A {@see StoredIdentityIntegrity} that answers with whatever the test seeded, so the command's three
 * outcomes can be exercised without a database.
 *
 * @internal
 */
final readonly class FixedStoredIdentityIntegrity implements StoredIdentityIntegrity
{
    /**
     * @param list<string> $orphanRoles
     */
    public function __construct(private array $orphanRoles, private int $unreadableCredentials)
    {
    }

    #[Override]
    public function roleValuesOutsideTheEnum(): array
    {
        return $this->orphanRoles;
    }

    #[Override]
    public function identitiesWithAnUnreadableCredential(): int
    {
        return $this->unreadableCredentials;
    }
}
