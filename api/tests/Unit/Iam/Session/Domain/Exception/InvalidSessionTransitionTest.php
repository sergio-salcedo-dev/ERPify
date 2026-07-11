<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Exception\InvalidSessionTransition;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvalidSessionTransition::class)]
final class InvalidSessionTransitionTest extends TestCase
{
    public function testItIsAConflictNamingBothStates(): void
    {
        $exception = InvalidSessionTransition::from(SessionStatus::REVOKED, SessionStatus::ACTIVE);

        $this->assertTrue((new ReflectionClass(InvalidSessionTransition::class))->implementsInterface(Conflict::class));
        $this->assertSame('invalid-session-transition', $exception->type());
        $this->assertStringContainsString('REVOKED', $exception->title());
        $this->assertStringContainsString('ACTIVE', $exception->title());
    }
}
