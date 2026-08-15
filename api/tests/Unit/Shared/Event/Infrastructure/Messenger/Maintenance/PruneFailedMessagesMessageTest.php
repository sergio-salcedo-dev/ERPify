<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneFailedMessagesMessage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PruneFailedMessagesMessage::class)]
final class PruneFailedMessagesMessageTest extends TestCase
{
    #[Test]
    public function itCarriesTheWindowTheDecisionChose(): void
    {
        $this->assertSame(30, (new PruneFailedMessagesMessage())->retentionDays);
    }

    #[Test]
    public function itAcceptsAnExplicitWindow(): void
    {
        $this->assertSame(90, (new PruneFailedMessagesMessage(90))->retentionDays);
    }

    #[Test]
    #[DataProvider('provideItRefusesAWindowShorterThanTheAlarmCanActInCases')]
    public function itRefusesAWindowShorterThanTheAlarmCanActIn(int $retentionDays): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PruneFailedMessagesMessage($retentionDays);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideItRefusesAWindowShorterThanTheAlarmCanActInCases(): iterable
    {
        // Zero is the dangerous one: the threshold becomes *now*, so the first tick empties the queue.
        yield 'zero' => [0];

        // A negative builds `'--30 days'`, which no date parser accepts — the tick dies daily, for ever.
        yield 'negative' => [-30];

        yield 'one day' => [1];
    }
}
