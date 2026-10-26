<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\Exception\SessionEvictedBeforeRevocation;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use Erpify\Shared\Monitoring\Domain\RoutineOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SessionEvictedBeforeRevocation::class)]
final class SessionEvictedBeforeRevocationTest extends TestCase
{
    public function testItAnswersExactlyWhatTheGateAnswers(): void
    {
        $exception = SessionEvictedBeforeRevocation::forRequest();
        $gate = SessionNoLongerActive::forRequest();

        $this->assertSame($gate->type(), $exception->type());
        $this->assertSame($gate->title(), $exception->title());
        $reflection = new ReflectionClass($exception);
        $this->assertTrue($reflection->implementsInterface(Unauthenticated::class));
    }

    public function testItIsReportedRatherThanRoutine(): void
    {
        $reflection = new ReflectionClass(SessionEvictedBeforeRevocation::class);

        $this->assertFalse($reflection->implementsInterface(RoutineOutcome::class));
    }
}
