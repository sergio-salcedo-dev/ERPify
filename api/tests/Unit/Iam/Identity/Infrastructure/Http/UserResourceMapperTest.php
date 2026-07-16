<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\Resource\UserDetailResource;
use Erpify\Iam\Identity\Application\Resource\UserListResource;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserResourceMapper::class)]
#[CoversClass(UserDetailResource::class)]
#[CoversClass(UserListResource::class)]
final class UserResourceMapperTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    private const string ATOM_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    public function testDetailResourcePinsSixKeysWithEnumValuesAndAtomTimestamps(): void
    {
        $user = UserFixtureFactory::create(
            self::USER_ID,
            'admin@erpify.test',
            'admin-password',
            [Role::ADMIN->value, Role::VIEWER->value],
        );

        $resource = (new UserResourceMapper())->toDetailResource($user);

        $this->assertSame(
            ['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::USER_ID, $resource->id);
        $this->assertSame('admin@erpify.test', $resource->email);
        $this->assertSame('ACTIVE', $resource->status);
        $this->assertSame(['ADMIN', 'VIEWER'], $resource->roles);
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $resource->createdAt);
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $resource->updatedAt);
    }

    public function testListPagePreservesAffordancesAndCursorsWhileMappingEachRow(): void
    {
        $page = new Page(
            items: [$this->row('a@erpify.test'), $this->row('b@erpify.test')],
            hasNext: true,
            hasPrev: false,
            nextCursor: 'opaque-next',
        );

        $mapped = (new UserResourceMapper())->toListPage($page);

        $this->assertContainsOnlyInstancesOf(UserListResource::class, $mapped->items);
        $this->assertCount(2, $mapped->items);
        $this->assertSame('a@erpify.test', $mapped->items[0]->email);
        $this->assertTrue($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertSame('opaque-next', $mapped->nextCursor);
        $this->assertNull($mapped->prevCursor);
    }

    public function testDetailMappingRejectsAUserWhoseIdWasNeverAssigned(): void
    {
        $user = UserFixtureFactory::create(
            self::USER_ID,
            'admin@erpify.test',
            'admin-password',
            [Role::ADMIN->value],
        );
        $user->setId(null);

        // A resource must carry a stable identifier; an unpersisted aggregate without an id is a caller
        // error, surfaced as a LogicException rather than emitting a null-id wire row.
        $this->expectException(LogicException::class);

        (new UserResourceMapper())->toDetailResource($user);
    }

    private function row(string $email): UserRow
    {
        return new UserRow(
            self::USER_ID,
            $email,
            ['VIEWER'],
            IdentityStatus::ACTIVE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
