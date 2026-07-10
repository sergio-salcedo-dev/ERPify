<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(SessionStoreUnavailable::class)]
final class SessionStoreUnavailableTest extends TestCase
{
    public function testItIsAServiceUnavailableThatIsNotAClientErrorAndKeepsItsCause(): void
    {
        $cause = new RuntimeException('connection refused');

        $exception = SessionStoreUnavailable::storeUnreachable($cause);

        $reflection = new ReflectionClass(SessionStoreUnavailable::class);
        $this->assertTrue($reflection->implementsInterface(ServiceUnavailable::class));
        $this->assertFalse(
            $reflection->implementsInterface(ClientError::class),
            'a 503 operational fault must not be a ClientError, so it is not suppressed in Sentry',
        );
        $this->assertSame('service-unavailable', $exception->type());
        $this->assertSame($cause, $exception->getPrevious());
    }
}
