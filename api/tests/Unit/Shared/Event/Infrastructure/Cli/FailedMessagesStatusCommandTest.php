<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\DeadLetterEntry;
use Erpify\Shared\Event\Application\DeadLetterReader;
use Erpify\Shared\Event\Infrastructure\Cli\FailedMessagesStatusCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(FailedMessagesStatusCommand::class)]
final class FailedMessagesStatusCommandTest extends TestCase
{
    #[Test]
    public function itReportsAnEmptyQueue(): void
    {
        $tester = new CommandTester($this->command($this->reader(0, [])));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('empty', $tester->getDisplay());
    }

    #[Test]
    public function itSummarisesTheBacklogByTypeAndAge(): void
    {
        $reader = $this->reader(2, [
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-22 09:00:00')),
            $this->entry('App\Event\BankCreated', new DateTimeImmutable('2026-06-22 11:00:00')),
        ]);

        $tester = new CommandTester($this->command($reader));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('2 message(s)', $display);
        $this->assertStringContainsString('App\Event\BankCreated', $display);
        $this->assertStringContainsString('Oldest failure', $display);
    }

    #[Test]
    public function itEmitsMachineReadableJson(): void
    {
        $failedAt = new DateTimeImmutable('2026-06-22 09:00:00');
        $reader = $this->reader(1, [$this->entry('App\Event\BankCreated', $failedAt)]);

        $tester = new CommandTester($this->command($reader));
        $tester->execute(['--json' => true]);

        $tester->assertCommandIsSuccessful();

        $payload = \json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'total' => 1,
            'countByType' => ['App\Event\BankCreated' => 1],
            'oldestFailedAt' => $failedAt->format(DateTimeImmutable::ATOM),
            'oldestAgeSeconds' => 3600,
            'messages' => [[
                'messageType' => 'App\Event\BankCreated',
                'eventId' => 'event-App\Event\BankCreated',
                'failedAt' => $failedAt->format(DateTimeImmutable::ATOM),
                'errorClass' => 'RuntimeException',
                'errorMessage' => 'boom',
            ]],
        ], $payload);
    }

    #[Test]
    public function itRejectsANonPositiveLimit(): void
    {
        $tester = new CommandTester($this->command($this->reader(0, [])));
        $tester->execute(['--limit' => 'all']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('positive integer', $tester->getDisplay());
    }

    #[Test]
    public function itForwardsTheLimitToTheReader(): void
    {
        $reader = $this->createMock(DeadLetterReader::class);
        $reader->method('count')->willReturn(0);
        $reader->expects($this->once())->method('entries')->with(5)->willReturn([]);

        $tester = new CommandTester($this->command($reader));
        $tester->execute(['--limit' => '5']);

        $tester->assertCommandIsSuccessful();
    }

    private function command(DeadLetterReader $reader): FailedMessagesStatusCommand
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-06-22 10:00:00'));

        return new FailedMessagesStatusCommand($reader, $clock);
    }

    /**
     * @param list<DeadLetterEntry> $entries
     */
    private function reader(int $count, array $entries): DeadLetterReader
    {
        $reader = $this->createStub(DeadLetterReader::class);
        $reader->method('count')->willReturn($count);
        $reader->method('entries')->willReturn($entries);

        return $reader;
    }

    private function entry(string $messageType, DateTimeImmutable $failedAt): DeadLetterEntry
    {
        return new DeadLetterEntry($messageType, 'event-' . $messageType, $failedAt, 'RuntimeException', 'boom');
    }
}
