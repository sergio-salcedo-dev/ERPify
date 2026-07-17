<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The catalog is the enumerable half of the authorization plane: the policy answers "may these roles do X?"
 * one permission at a time, so anything that needs the *set* (the `/me` read-model) must have a list to walk.
 * These cases pin the vocabulary itself; that every gated route appears in it is a separate, mechanical gate
 * ({@see PermissionCatalogCoversEveryGatedRouteTest}).
 *
 * @internal
 */
#[CoversClass(PermissionCatalog::class)]
final class PermissionCatalogTest extends TestCase
{
    /**
     * The vocabulary the installation publishes today. Spelled out here rather than derived from the catalog,
     * so a silent edit to the catalog fails this test instead of redefining its own expectation.
     *
     * @var list<string>
     */
    private const array EXPECTED = [
        'auditTrail.read',
        'bank.read',
        'bank.write',
        'bank.delete',
        'bankAccount.read',
        'bankAccount.write',
        'bankAccount.delete',
        'bankAccount.changeStatus',
        'users.read',
        'users.invite',
        'users.changeStatus',
        'users.erase',
    ];

    public function testItPublishesTheWholeKnownPermissionVocabulary(): void
    {
        $permissions = \array_map(
            static fn (Permission $permission): string => $permission->toString(),
            (new PermissionCatalog())->all(),
        );

        \sort($permissions);
        $expected = self::EXPECTED;
        \sort($expected);

        $this->assertSame($expected, $permissions);
    }

    /**
     * A malformed entry would be rejected by `Permission::fromString()` at construction, so this asserts the
     * catalog never ships one — the wire contract carries these strings verbatim to the PWA (SI-20).
     */
    public function testEveryCatalogedPermissionIsWellFormed(): void
    {
        foreach ((new PermissionCatalog())->all() as $permission) {
            $this->assertTrue(Permission::isWellFormed($permission->toString()));
        }
    }

    public function testItHoldsNoDuplicates(): void
    {
        $permissions = \array_map(
            static fn (Permission $permission): string => $permission->toString(),
            (new PermissionCatalog())->all(),
        );

        $this->assertSame($permissions, \array_values(\array_unique($permissions)));
    }
}
