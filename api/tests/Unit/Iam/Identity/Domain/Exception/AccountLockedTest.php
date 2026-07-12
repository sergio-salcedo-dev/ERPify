<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\AccountLocked;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AccountLocked::class)]
final class AccountLockedTest extends TestCase
{
    #[Test]
    public function itIsASpecificForbiddenWallCarryingItsOwnType(): void
    {
        $exception = new AccountLocked();

        $this->assertSame('account-locked', $exception->type());
        $this->assertSame(
            'Your account is temporarily locked after too many failed attempts. '
                . 'Reset your password to regain access.',
            $exception->title(),
        );
    }

    #[Test]
    public function itMapsToA403ThroughTheForbiddenMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(AccountLocked::class))->implementsInterface(Forbidden::class),
        );
    }
}
