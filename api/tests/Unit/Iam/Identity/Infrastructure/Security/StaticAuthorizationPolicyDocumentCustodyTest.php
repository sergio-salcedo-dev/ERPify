<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `document.custody` is ADMIN-only, exactly as `document.download`: no tier carries the verb, and
 * the declared row grants ADMIN with the tiers emptied, so the wildcard cannot be what makes the assertion true.
 *
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyDocumentCustodyTest extends TestCase
{
    public function testAdminMayReadTheCustodyTrail(): void
    {
        $this->assertTrue(
            (new StaticAuthorizationPolicy())->permits(
                Permission::fromString('document.custody'),
                [Role::ADMIN->value],
            ),
        );
    }

    public function testTheDeclaredRowGrantsAdminWithoutTheWildcard(): void
    {
        $policy = new StaticAuthorizationPolicy(tierVerbs: []);

        $this->assertTrue($policy->permits(Permission::fromString('document.custody'), [Role::ADMIN->value]));
    }

    #[DataProvider('provideEveryOtherRoleIsDeniedCases')]
    public function testEveryOtherRoleIsDenied(string $role): void
    {
        $this->assertFalse(
            (new StaticAuthorizationPolicy())->permits(Permission::fromString('document.custody'), [$role]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEveryOtherRoleIsDeniedCases(): iterable
    {
        foreach (Role::cases() as $role) {
            if (Role::ADMIN !== $role) {
                yield $role->value => [$role->value];
            }
        }
    }
}
