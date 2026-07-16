<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Policy coverage for the `users` resource — the identity console. It lives in its own class because
 * {@see StaticAuthorizationPolicyTest} (the bank/audit resource cases) is at the public-method cap; the
 * users resource is a distinct opt-out concern and reads clearly on its own.
 *
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyUsersResourceTest extends TestCase
{
    #[DataProvider('provideAdminIsGrantedEveryUsersActionCases')]
    public function testAdminIsGrantedEveryUsersAction(string $action): void
    {
        $policy = new StaticAuthorizationPolicy();

        $this->assertTrue($policy->permits(Permission::fromString($action), [Role::ADMIN->value]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAdminIsGrantedEveryUsersActionCases(): iterable
    {
        yield 'read' => ['users.read'];
        yield 'invite' => ['users.invite'];
        yield 'changeStatus' => ['users.changeStatus'];
        yield 'erase' => ['users.erase'];
    }

    public function testEveryNonAdminTierIsDeniedUsersReadThroughTheOptOut(): void
    {
        $policy = new StaticAuthorizationPolicy();
        $read = Permission::fromString('users.read');

        // users opts out of tier auto-grant, so no generic tier earns it without an explicit grant: this
        // opt-out is what makes users.read ADMIN-only. read is a tier verb VIEWER would otherwise auto-hold,
        // so without the opt-out the console would silently open to every read tier.
        $this->assertFalse($policy->permits($read, [Role::VIEWER->value]));
        $this->assertFalse($policy->permits($read, [Role::EDITOR->value]));
        $this->assertFalse($policy->permits($read, [Role::MANAGER->value]));
        $this->assertFalse($policy->permits($read, []));
    }

    public function testTheRefusalIsOptOutSpecificNotABrokenReadTier(): void
    {
        $policy = new StaticAuthorizationPolicy();

        // The same VIEWER refused the console still reads a tiered resource — bank — pinning that only users'
        // opt-out withholds it, not a broken read tier. Also guards a regression adding bank to tierOptOut.
        $this->assertTrue($policy->permits(Permission::fromString('bank.read'), [Role::VIEWER->value]));
    }
}
