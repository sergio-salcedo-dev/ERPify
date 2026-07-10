<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LastActiveAdministratorProtected::class)]
final class LastActiveAdministratorProtectedTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itDescribesTheProtectedInvariantAndNamesTheIdentity(): void
    {
        $exception = LastActiveAdministratorProtected::forUser(self::USER_ID);

        $this->assertSame('last-active-administrator-protected', $exception->type());
        $this->assertSame(
            'Cannot suspend or deactivate the last active administrator of the organization.',
            $exception->title(),
        );
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(LastActiveAdministratorProtected::class))->implementsInterface(Conflict::class),
        );
    }
}
