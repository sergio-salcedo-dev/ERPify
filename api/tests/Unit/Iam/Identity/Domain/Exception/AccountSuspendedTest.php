<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AccountSuspended::class)]
final class AccountSuspendedTest extends TestCase
{
    #[Test]
    public function itIsASpecificForbiddenWallCarryingItsOwnType(): void
    {
        $exception = new AccountSuspended();

        $this->assertSame('account-suspended', $exception->type());
        $this->assertSame(
            'Your account has been suspended. Contact an administrator to restore access.',
            $exception->title(),
        );
    }

    #[Test]
    public function itMapsToA403ThroughTheForbiddenMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(AccountSuspended::class))->implementsInterface(Forbidden::class),
        );
    }
}
