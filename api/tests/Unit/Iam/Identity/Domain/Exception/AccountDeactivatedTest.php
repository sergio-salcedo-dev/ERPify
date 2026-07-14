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
    public function itCarriesTheSpecificAccountDeactivatedType(): void
    {
        $exception = new AccountDeactivated();

        // A specific type() so a client tells this terminal admission wall apart from a generic infrastructural
        // 'forbidden' (an origin/CSRF rejection) that shares the 403 status.
        $this->assertSame('account-deactivated', $exception->type());
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
