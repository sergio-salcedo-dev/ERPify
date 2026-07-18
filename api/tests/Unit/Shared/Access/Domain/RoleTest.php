<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Access\Domain;

use Erpify\Shared\Access\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    /**
     * The backing value is the string persisted in the `roles` JSON column and the stem of the Symfony
     * ROLE_* grant, so a silent rename would orphan stored rows and RBAC mappings while the enum keeps
     * compiling — this pins each role's value.
     */
    #[DataProvider('provideRoleBacksItsExpectedCanonicalValueCases')]
    public function testRoleBacksItsExpectedCanonicalValue(string $expectedValue, Role $role): void
    {
        $this->assertSame($expectedValue, $role->value);
    }

    /**
     * @return iterable<string, array{string, Role}>
     */
    public static function provideRoleBacksItsExpectedCanonicalValueCases(): iterable
    {
        yield 'VIEWER' => ['VIEWER', Role::VIEWER];
        yield 'EDITOR' => ['EDITOR', Role::EDITOR];
        yield 'MANAGER' => ['MANAGER', Role::MANAGER];
        yield 'ADMIN' => ['ADMIN', Role::ADMIN];
        yield 'AUDIT_READER' => ['AUDIT_READER', Role::AUDIT_READER];
    }

    public function testNoRoleValueCarriesTheFrameworkPrefix(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertStringStartsNotWith('ROLE_', $role->value);
        }
    }
}
