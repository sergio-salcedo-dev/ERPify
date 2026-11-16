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
 * `document.download` is decided ADMIN-only (2026-11-16): `download` is no tier's verb, so no tier reaches it, and
 * the ADMIN wildcard and a declared explicit row both do. The row is pinned on its own, with the tiers emptied, so
 * the wildcard cannot be what makes the assertion true.
 *
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyDocumentDownloadTest extends TestCase
{
    public function testAdminMayDownload(): void
    {
        $this->assertTrue(
            (new StaticAuthorizationPolicy())->permits(
                Permission::fromString('document.download'),
                [Role::ADMIN->value],
            ),
        );
    }

    public function testTheDeclaredRowGrantsAdminWithoutTheWildcard(): void
    {
        $policy = new StaticAuthorizationPolicy(tierVerbs: []);

        $this->assertTrue($policy->permits(Permission::fromString('document.download'), [Role::ADMIN->value]));
    }

    #[DataProvider('provideEveryOtherRoleIsDeniedCases')]
    public function testEveryOtherRoleIsDenied(string $role): void
    {
        $this->assertFalse(
            (new StaticAuthorizationPolicy())->permits(Permission::fromString('document.download'), [$role]),
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
