<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Application\DeadLetterReader;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\ReportDeadLetterBacklogHandler;
use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\ReportDeadLetterBacklogMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(ReportDeadLetterBacklogHandler::class)]
final class ReportDeadLetterBacklogHandlerTest extends TestCase
{
    private const string NOW = '2026-06-22 10:00:00';

    #[Test]
    public function itAlarmsWhenTheBacklogExceedsTheThreshold(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new ReportDeadLetterBacklogHandler(
            $this->reader(3, $this->hoursAgo(1)),
            $this->clock(),
            $logger,
        );

        $handler(new ReportDeadLetterBacklogMessage());
    }

    #[Test]
    public function itStaysSilentWhenTheQueueIsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $handler = new ReportDeadLetterBacklogHandler($this->reader(0, null), $this->clock(), $logger);

        $handler(new ReportDeadLetterBacklogMessage());
    }

    #[Test]
    public function itStaysSilentWhenWithinBothThresholds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $handler = new ReportDeadLetterBacklogHandler(
            $this->reader(2, $this->hoursAgo(1)),
            $this->clock(),
            $logger,
        );

        $handler(new ReportDeadLetterBacklogMessage(maxBacklog: 5, maxAgeHours: 24));
    }

    #[Test]
    public function itAlarmsOnAgeEvenWhenTheBacklogIsWithinThreshold(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new ReportDeadLetterBacklogHandler(
            $this->reader(1, $this->hoursAgo(48)),
            $this->clock(),
            $logger,
        );

        $handler(new ReportDeadLetterBacklogMessage(maxBacklog: 5, maxAgeHours: 24));
    }

    private function reader(int $count, ?DateTimeImmutable $oldestFailedAt): DeadLetterReader
    {
        $entries = $oldestFailedAt instanceof DateTimeImmutable
            ? [new DeadLetterEntry('App\Event\X', null, $oldestFailedAt, null, null)]
            : [];

        $reader = $this->createStub(DeadLetterReader::class);
        $reader->method('count')->willReturn($count);
        $reader->method('entries')->willReturn($entries);

        return $reader;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        return $clock;
    }

    private function hoursAgo(int $hours): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::NOW))->modify(\sprintf('-%d hours', $hours));
    }
}
