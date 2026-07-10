<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AccountDeactivated::class)]
final class AccountDeactivatedTest extends TestCase
{
    #[Test]
    public function itIsAGenericForbiddenWallWithoutAnActionableType(): void
    {
        $exception = new AccountDeactivated();

        // Empty type() falls through to the Forbidden marker default ('forbidden') at the wire — deliberately
        // generic, since a retired identity has no next step from the login screen.
        $this->assertSame('', $exception->type());
        $this->assertSame('Your account is not active.', $exception->title());
    }

    #[Test]
    public function itMapsToA403ThroughTheForbiddenMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(AccountDeactivated::class))->implementsInterface(Forbidden::class),
        );
    }
}
