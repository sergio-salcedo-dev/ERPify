<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Application;

use DateTimeImmutable;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Application\DeadLetterSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DeadLetterSummary::class)]
#[CoversClass(DeadLetterEntry::class)]
final class DeadLetterSummaryTest extends TestCase
{
    #[Test]
    public function itIsEmptyWhenNothingHasFailed(): void
    {
        $summary = DeadLetterSummary::fromEntries(0, []);

        $this->assertTrue($summary->isEmpty());
        $this->assertSame([], $summary->countByType);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $summary->oldestFailedAt);
    }

    #[Test]
    public function itCountsEntriesByMessageTypeSortedDescending(): void
    {
        $summary = DeadLetterSummary::fromEntries(3, [
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-20 10:00:00')),
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-20 11:00:00')),
            $this->entry('App\Mail\SendDigest', new DateTimeImmutable('2026-06-20 09:00:00')),
        ]);

        $this->assertFalse($summary->isEmpty());
        $this->assertSame(3, $summary->total);
        $this->assertSame(
            ['App\Event\BankCreated' => 2, 'App\Mail\SendDigest' => 1],
            $summary->countByType,
        );
    }

    #[Test]
    public function itKeepsTheOldestFailureInstant(): void
    {
        $summary = DeadLetterSummary::fromEntries(2, [
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-20 11:00:00')),
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-20 09:00:00')),
        ]);

        $this->assertSame(
            (new DateTimeImmutable('2026-06-20 09:00:00'))->format(DateTimeImmutable::ATOM),
            $summary->oldestFailedAt?->format(DateTimeImmutable::ATOM),
        );
    }

    #[Test]
    public function itPreservesTheTransportTotalEvenWhenEntriesAreLimited(): void
    {
        $summary = DeadLetterSummary::fromEntries(50, [
            $this->entry('App\Event\BankCreated', null),
        ]);

        $this->assertSame(50, $summary->total);
        $this->assertSame(['App\Event\BankCreated' => 1], $summary->countByType);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $summary->oldestFailedAt);
    }

    private function entry(string $messageType, ?DateTimeImmutable $failedAt): DeadLetterEntry
    {
        return new DeadLetterEntry($messageType, null, $failedAt, null, null);
    }
}
