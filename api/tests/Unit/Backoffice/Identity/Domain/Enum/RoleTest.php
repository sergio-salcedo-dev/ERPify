<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Domain\Enum;

use Erpify\Backoffice\Identity\Domain\Enum\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function testDeclaresAuditReaderAsADomainRole(): void
    {
        $this->assertContains(Role::AUDIT_READER, Role::cases());
    }

    public function testNoRoleValueCarriesTheFrameworkPrefix(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertStringStartsNotWith('ROLE_', $role->value);
        }
    }
}
