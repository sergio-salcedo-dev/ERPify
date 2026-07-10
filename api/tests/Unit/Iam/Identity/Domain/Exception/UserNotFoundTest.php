<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(UserNotFound::class)]
final class UserNotFoundTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itNamesTheMissingIdentity(): void
    {
        $exception = UserNotFound::withId(self::USER_ID);

        $this->assertSame('user-not-found', $exception->type());
        $this->assertSame('User not found.', $exception->title());
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA404ThroughTheNotFoundMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(UserNotFound::class))->implementsInterface(NotFound::class),
        );
    }
}
