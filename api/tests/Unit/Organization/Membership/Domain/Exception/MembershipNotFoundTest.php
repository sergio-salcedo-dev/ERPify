<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Domain\Exception;

use Erpify\Organization\Membership\Domain\Exception\MembershipNotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(MembershipNotFound::class)]
final class MembershipNotFoundTest extends TestCase
{
    public function testItIsANotFoundCarryingTheUserIdInContext(): void
    {
        $userId = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c60';

        $exception = MembershipNotFound::forUser($userId);

        $this->assertTrue((new ReflectionClass(MembershipNotFound::class))->implementsInterface(NotFound::class));
        $this->assertSame('membership-not-found', $exception->type());
        $this->assertSame(['userId' => $userId], $exception->context());
    }
}
