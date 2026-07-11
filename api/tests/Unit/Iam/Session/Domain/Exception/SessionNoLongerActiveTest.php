<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SessionNoLongerActive::class)]
final class SessionNoLongerActiveTest extends TestCase
{
    public function testItIsAnUnauthenticatedClientErrorWithTheSessionExpiredType(): void
    {
        $exception = SessionNoLongerActive::forRequest();

        $reflection = new ReflectionClass(SessionNoLongerActive::class);
        $this->assertTrue($reflection->implementsInterface(Unauthenticated::class));
        $this->assertTrue($reflection->implementsInterface(ClientError::class));
        $this->assertSame('session-expired', $exception->type());
    }
}
