<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\DeadLetterReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The dead-letter alarm: on each scheduled tick, emits a single structured `error` log line when the
 * failure transport breaches the backlog or age threshold, and stays silent otherwise. A log line
 * (not a Sentry capture) is the signal on purpose — the Monolog→Sentry bridge is deliberately left
 * unwired (see config/packages/sentry.yaml); the scheduled check is the "cron" arm of observability,
 * with `messenger:failed:status --json` as the scrapeable metric.
 */
#[AsMessageHandler]
final readonly class ReportDeadLetterBacklogHandler
{
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        private DeadLetterReader $reader,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportDeadLetterBacklogMessage $message): void
    {
        $total = $this->reader->count();

        if (0 === $total) {
            return;
        }

        $oldestAgeHours = $this->oldestAgeHours();

        if ($total <= $message->maxBacklog && $oldestAgeHours <= $message->maxAgeHours) {
            return;
        }

        $this->logger->error('Dead-letter backlog over threshold.', [
            'total' => $total,
            'maxBacklog' => $message->maxBacklog,
            'oldestAgeHours' => $oldestAgeHours,
            'maxAgeHours' => $message->maxAgeHours,
        ]);
    }

    private function oldestAgeHours(): int
    {
        $oldest = $this->reader->entries(1)[0]->failedAt ?? null;

        if (null === $oldest) {
            return 0;
        }

        return \intdiv(\max(0, $this->clock->now()->getTimestamp() - $oldest->getTimestamp()), self::SECONDS_PER_HOUR);
    }
}
