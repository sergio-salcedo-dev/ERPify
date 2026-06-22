<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use DateTimeImmutable;

/**
 * Aggregate view of the failure transport: how many messages rest there, how they break down by
 * message type, and the instant of the oldest one. {@see $total} comes from the transport's own count
 * and may exceed the entries folded into {@see $countByType} when inspection is limited.
 */
final readonly class DeadLetterSummary
{
    /**
     * @param array<string, int> $countByType message type FQCN => number of resting messages
     */
    public function __construct(
        public int $total,
        public array $countByType,
        public ?DateTimeImmutable $oldestFailedAt,
    ) {
    }

    /**
     * @param list<DeadLetterEntry> $entries
     */
    public static function fromEntries(int $total, array $entries): self
    {
        $countByType = [];
        $oldestFailedAt = null;

        foreach ($entries as $entry) {
            $countByType[$entry->messageType] = ($countByType[$entry->messageType] ?? 0) + 1;

            if (null !== $entry->failedAt && (null === $oldestFailedAt || $entry->failedAt < $oldestFailedAt)) {
                $oldestFailedAt = $entry->failedAt;
            }
        }

        \arsort($countByType);

        return new self($total, $countByType, $oldestFailedAt);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }
}
