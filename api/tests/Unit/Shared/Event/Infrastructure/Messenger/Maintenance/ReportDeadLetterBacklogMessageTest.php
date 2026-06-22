<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\ReportDeadLetterBacklogMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReportDeadLetterBacklogMessage::class)]
final class ReportDeadLetterBacklogMessageTest extends TestCase
{
    #[Test]
    public function itAlarmsOnAnyBacklogByDefault(): void
    {
        $message = new ReportDeadLetterBacklogMessage();

        $this->assertSame(0, $message->maxBacklog);
        $this->assertSame(24, $message->maxAgeHours);
    }

    #[Test]
    public function itAcceptsCustomThresholds(): void
    {
        $message = new ReportDeadLetterBacklogMessage(maxBacklog: 10, maxAgeHours: 6);

        $this->assertSame(10, $message->maxBacklog);
        $this->assertSame(6, $message->maxAgeHours);
    }
}
