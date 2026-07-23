<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\AdministratorErasureRequiresDemotion;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AdministratorErasureRequiresDemotion::class)]
final class AdministratorErasureRequiresDemotionTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    #[Test]
    public function itNamesTheIdentityAndSendsTheCallerToTheDemotion(): void
    {
        $exception = AdministratorErasureRequiresDemotion::forUser(self::USER_ID);

        $this->assertSame('administrator-erasure-requires-demotion', $exception->type());
        $this->assertSame(
            'Cannot erase an identity that still holds the administrator role; remove the role first.',
            $exception->title(),
        );
        $this->assertSame(['userId' => self::USER_ID], $exception->context());
    }

    #[Test]
    public function itMapsToA409ThroughTheConflictMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(AdministratorErasureRequiresDemotion::class))->implementsInterface(Conflict::class),
        );
    }
}
