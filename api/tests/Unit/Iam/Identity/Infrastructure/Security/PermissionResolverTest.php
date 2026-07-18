<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\Permission;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionCatalog;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionResolver;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `/me` read-model: the whole catalog filtered through the production policy. Driven against the real
 * {@see StaticAuthorizationPolicy} rather than a stub, because the value under test *is* the interaction —
 * a stub would only re-assert the loop. The expectations below are therefore also a readable statement of
 * what each role actually holds.
 *
 * @internal
 */
#[CoversClass(PermissionResolver::class)]
final class PermissionResolverTest extends TestCase
{
    /**
     * @param list<string> $roles
     * @param list<string> $expected
     */
    #[DataProvider('provideItResolvesTheHeldPermissionsCases')]
    public function testItResolvesTheHeldPermissions(array $roles, array $expected): void
    {
        $resolved = $this->resolver()->forRoles($roles);

        \sort($resolved);
        \sort($expected);

        $this->assertSame($expected, $resolved);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function provideItResolvesTheHeldPermissionsCases(): iterable
    {
        $tierRead = ['bank.read', 'bankAccount.read'];
        $managerTier = [
            'bank.read', 'bank.write', 'bank.delete',
            'bankAccount.read', 'bankAccount.write', 'bankAccount.delete',
        ];

        // The superuser clause short-circuits every permission, opt-out resources included.
        yield 'admin holds the whole catalog' => [[Role::ADMIN->value], self::wholeCatalog()];

        // `auditTrail` and `users` opt out of tier auto-grant, so a read verb does not reach them.
        yield 'viewer holds only tier reads on non-opt-out resources' => [[Role::VIEWER->value], $tierRead];

        // AUDIT_READER is absent from TIER_VERBS: an orthogonal capability grant, not a rung on the ladder.
        yield 'audit reader holds exactly its explicit grant' => [[Role::AUDIT_READER->value], ['auditTrail.read']];

        yield 'roles compose additively' => [
            [Role::VIEWER->value, Role::AUDIT_READER->value],
            [...$tierRead, 'auditTrail.read'],
        ];

        // `bankAccount.changeStatus` is not a tier verb — MANAGER reaches it through an explicit grant.
        yield 'audit reader and manager compose tier verbs with both explicit grants' => [
            [Role::AUDIT_READER->value, Role::MANAGER->value],
            [...$managerTier, 'bankAccount.changeStatus', 'auditTrail.read'],
        ];

        yield 'no roles hold nothing' => [[], []];
    }

    /**
     * @return list<string>
     */
    private static function wholeCatalog(): array
    {
        return \array_map(
            static fn (Permission $permission): string => $permission->toString(),
            (new PermissionCatalog())->all(),
        );
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(new PermissionCatalog(), new StaticAuthorizationPolicy());
    }
}
