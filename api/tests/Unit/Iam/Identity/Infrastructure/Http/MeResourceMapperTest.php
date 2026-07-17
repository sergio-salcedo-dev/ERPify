<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Infrastructure\Http\MeResourceMapper;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionResolver;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MeResourceMapper::class)]
final class MeResourceMapperTest extends TestCase
{
    public function testItMapsIdEmailAndTheDomainRoleNamesWithoutTheRolePrefix(): void
    {
        $user = new SecurityUser(UserMother::create(roles: [Role::ADMIN, Role::AUDIT_READER]));

        $resource = $this->mapper()->toResource($user);

        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $resource->email);
        $this->assertSame(['ADMIN', 'AUDIT_READER'], $resource->roles);
    }

    /**
     * The resolver is fed the *stripped* names, so an ADMIN resolves to the whole catalog. Were the mapper to
     * hand it the firewall's `ROLE_ADMIN` tokens instead, the policy would match no role and this would come
     * back empty — which is the regression this case exists to catch.
     */
    public function testItDerivesThePermissionSetFromTheStrippedRoleNames(): void
    {
        $user = new SecurityUser(UserMother::create(roles: [Role::ADMIN]));

        $resource = $this->mapper()->toResource($user);

        $expected = \array_map(
            static fn (Permission $permission): string => $permission->toString(),
            (new PermissionCatalog())->all(),
        );
        $this->assertSame($expected, $resource->permissions);
    }

    /**
     * A role the policy grants nothing for still maps — it simply holds nothing. `VIEWER` cannot reach the
     * identity plane, so `/me` never advertises a `users.*` capability to it.
     */
    public function testItMapsARoleThatHoldsNoIdentityPermissions(): void
    {
        $user = new SecurityUser(UserMother::create(roles: [Role::VIEWER]));

        $resource = $this->mapper()->toResource($user);

        $this->assertSame(['bank.read', 'bankAccount.read'], $resource->permissions);
    }

    private function mapper(): MeResourceMapper
    {
        return new MeResourceMapper(new PermissionResolver(new PermissionCatalog(), new StaticAuthorizationPolicy()));
    }
}
