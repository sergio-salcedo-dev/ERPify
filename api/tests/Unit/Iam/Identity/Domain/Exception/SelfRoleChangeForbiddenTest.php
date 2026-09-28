<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\SelfRoleChangeForbidden;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SelfRoleChangeForbidden::class)]
final class SelfRoleChangeForbiddenTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itCarriesItsOwnTypeAndNamesTheIdentity(): void
    {
        $exception = SelfRoleChangeForbidden::forActor(self::USER_ID);

        $this->assertSame('self-role-change-forbidden', $exception->type());
        $this->assertSame('An administrator cannot change their own roles.', $exception->title());
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(SelfRoleChangeForbidden::class))->implementsInterface(Conflict::class),
        );
    }
}
