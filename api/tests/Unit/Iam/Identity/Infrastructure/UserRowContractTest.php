<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Byte-stability gate for the register-row wire contract: pins the EXACT six-key set the list view emits,
 * the enum-identity `status` value, the roles list carried verbatim, and the ATOM timestamp format — and
 * that the keyset columns never leak a credential field. A key added, removed, renamed or reordered fails
 * here, independent of any HTTP snapshot.
 *
 * @internal
 */
#[CoversClass(UserResourceMapper::class)]
final class UserRowContractTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    public function testListResourcePinsSixKeysWithEnumValueAndRolesList(): void
    {
        $resource = (new UserResourceMapper())->toListResource($this->row(
            roles: ['ADMIN', 'VIEWER'],
            status: IdentityStatus::ACTIVE,
        ));

        $this->assertSame(
            ['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::USER_ID, $resource->id);
        $this->assertSame('admin@erpify.test', $resource->email);
        $this->assertSame('ACTIVE', $resource->status);
        $this->assertSame(['ADMIN', 'VIEWER'], $resource->roles);
        $this->assertSame('2026-01-01T00:00:00+00:00', $resource->createdAt);
        $this->assertSame('2026-01-02T00:00:00+00:00', $resource->updatedAt);
    }

    public function testListResourceCarriesAnEmptyRolesListWithoutInventingOne(): void
    {
        $resource = (new UserResourceMapper())->toListResource($this->row(
            roles: [],
            status: IdentityStatus::INVITED,
        ));

        $this->assertSame([], $resource->roles);
        $this->assertSame('INVITED', $resource->status);
    }

    /**
     * @param list<string> $roles
     */
    private function row(array $roles, IdentityStatus $status): UserRow
    {
        return new UserRow(
            self::USER_ID,
            'admin@erpify.test',
            $roles,
            $status,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
