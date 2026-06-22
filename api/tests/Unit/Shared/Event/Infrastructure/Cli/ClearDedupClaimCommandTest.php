<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Cli;

use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Erpify\Shared\Event\Infrastructure\Cli\ClearDedupClaimCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(ClearDedupClaimCommand::class)]
final class ClearDedupClaimCommandTest extends TestCase
{
    private const string EVENT_ID = '0190e2c0-1111-7000-8000-000000000000';

    private const string HANDLER = \Erpify\Backoffice\Bank\Infrastructure\Messenger\SendEmailOnBankChanged::class;

    #[Test]
    public function itReleasesTheClaimForTheGivenEventAndHandler(): void
    {
        $deduplicator = $this->createMock(DomainEventHandlerDeduplicator::class);
        $deduplicator->expects($this->once())->method('release')->with(self::EVENT_ID, self::HANDLER)->willReturn(1);

        $tester = new CommandTester(new ClearDedupClaimCommand($deduplicator));
        $tester->execute(['eventId' => self::EVENT_ID, 'handler' => self::HANDLER]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('messenger:failed:retry', $tester->getDisplay());
    }

    #[Test]
    public function itWarnsWhenNoClaimMatched(): void
    {
        $deduplicator = $this->createMock(DomainEventHandlerDeduplicator::class);
        $deduplicator->expects($this->once())->method('release')->with(self::EVENT_ID, self::HANDLER)->willReturn(0);

        $tester = new CommandTester(new ClearDedupClaimCommand($deduplicator));
        $tester->execute(['eventId' => self::EVENT_ID, 'handler' => self::HANDLER]);

        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        $this->assertStringContainsString('No dedup claim found', $display);
        $this->assertStringNotContainsString('Now run: messenger:failed:retry', $display);
    }

    #[Test]
    public function itRejectsAnEmptyArgument(): void
    {
        $deduplicator = $this->createMock(DomainEventHandlerDeduplicator::class);
        $deduplicator->expects($this->never())->method('release');

        $tester = new CommandTester(new ClearDedupClaimCommand($deduplicator));
        $tester->execute(['eventId' => '', 'handler' => self::HANDLER]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
