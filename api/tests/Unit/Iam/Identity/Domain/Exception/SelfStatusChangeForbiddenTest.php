<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\SelfStatusChangeForbidden;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SelfStatusChangeForbidden::class)]
final class SelfStatusChangeForbiddenTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itCarriesItsOwnTypeAndNamesTheIdentity(): void
    {
        $exception = SelfStatusChangeForbidden::forActor(self::USER_ID);

        $this->assertSame('self-status-change-forbidden', $exception->type());
        $this->assertSame(
            'An administrator cannot change the status of their own account.',
            $exception->title(),
        );
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(SelfStatusChangeForbidden::class))->implementsInterface(Conflict::class),
        );
    }
}
