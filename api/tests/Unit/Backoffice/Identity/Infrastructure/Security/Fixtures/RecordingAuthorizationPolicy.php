<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security\Fixtures;

use Erpify\Backoffice\Identity\Infrastructure\Security\AuthorizationPolicy;
use Erpify\Backoffice\Identity\Infrastructure\Security\Permission;
use Override;

/**
 * In-tree fake of {@see AuthorizationPolicy} that records how the voter called it (the permission built and
 * the bare role tokens handed over) and returns a fixed decision. It lets a unit test assert the voter's
 * edge behaviour — `ROLE_` stripping and delegation — without a real policy, mocks or the container.
 */
final class RecordingAuthorizationPolicy implements AuthorizationPolicy
{
    /** @var list<array{permission: Permission, roles: list<string>}> */
    public array $calls = [];

    public function __construct(private readonly bool $decision)
    {
    }

    /**
     * @param list<string> $roles
     */
    #[Override]
    public function permits(Permission $permission, array $roles): bool
    {
        $this->calls[] = ['permission' => $permission, 'roles' => $roles];

        return $this->decision;
    }
}
