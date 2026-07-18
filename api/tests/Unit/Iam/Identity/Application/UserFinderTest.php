<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\UserFinder;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserFinder::class)]
final class UserFinderTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    public function testReturnsTheUserWhenAWellFormedIdResolvesToARow(): void
    {
        $user = UserFixtureFactory::create(self::USER_ID, 'admin@erpify.test', 'admin-password', [Role::ADMIN->value]);

        $found = (new UserFinder(new InMemoryUserRepository($user)))->find(self::USER_ID);

        $this->assertSame($user, $found);
    }

    public function testThrowsNotFoundWhenAWellFormedIdMatchesNoRow(): void
    {
        $this->expectException(UserNotFound::class);

        (new UserFinder(new InMemoryUserRepository()))->find(self::USER_ID);
    }

    public function testRejectsAMalformedIdBeforeTouchingTheRepository(): void
    {
        $this->expectException(InvalidUuidException::class);

        (new UserFinder(new InMemoryUserRepository()))->find('not-a-uuid');
    }
}
