<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\PruneRetiredSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Infrastructure\Cli\PruneRetiredSessionsCommand;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(PruneRetiredSessionsCommand::class)]
final class PruneRetiredSessionsCommandTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    #[Override]
    protected function tearDown(): void
    {
        SystemClock::reset();
    }

    public function testPrunesOnlyRetiredSessionsAndReportsTheCount(): void
    {
        $sessions = new InMemorySessionRepository($this->longRevokedSession(), $this->liveSession());
        $tester = new CommandTester(
            new PruneRetiredSessionsCommand(
                new PruneRetiredSessions($sessions, new FixedClock(new DateTimeImmutable(self::NOW))),
            ),
        );

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        // One and not two: the count in the report is what an operator reads to decide whether the sweep is
        // doing anything, so a report that over-counted would be worse than no report.
        $this->assertStringContainsString('Pruned 1 retired session(s).', $tester->getDisplay());
    }

    public function testReportsZeroWhenThereIsNothingToSweep(): void
    {
        $sessions = new InMemorySessionRepository($this->liveSession());
        $tester = new CommandTester(
            new PruneRetiredSessionsCommand(
                new PruneRetiredSessions($sessions, new FixedClock(new DateTimeImmutable(self::NOW))),
            ),
        );

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Pruned 0 retired session(s).', $tester->getDisplay());
    }

    private function longRevokedSession(): Session
    {
        $now = new DateTimeImmutable(self::NOW);
        $session = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));

        SystemClock::set(new FixedClock($now->modify('-31 days')));
        $session->revoke();

        return $session;
    }

    private function liveSession(): Session
    {
        $now = new DateTimeImmutable(self::NOW);

        return SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify('+1 hour'));
    }
}
