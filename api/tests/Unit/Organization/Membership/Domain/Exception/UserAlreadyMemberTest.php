<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Exception;

use Erpify\Organization\Membership\Domain\Exception\UserAlreadyMember;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(UserAlreadyMember::class)]
final class UserAlreadyMemberTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itDescribesTheCollisionAndNamesTheUser(): void
    {
        $exception = new UserAlreadyMember(self::USER_ID);

        $this->assertSame('user-already-member', $exception->type());
        $this->assertSame('This user already belongs to the organization.', $exception->title());
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(UserAlreadyMember::class))->implementsInterface(Conflict::class),
        );
    }
}
