<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Infrastructure\Cli\EraseIdentitySubjectCommand;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(EraseIdentitySubjectCommand::class)]
final class EraseIdentitySubjectCommandTest extends TestCase
{
    public function testRejectsAMalformedUserId(): void
    {
        $tester = $this->tester(new InMemoryUserRepository());

        $exitCode = $tester->execute(['user-id' => 'not-a-uuid']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('must be a valid UUID', $tester->getDisplay());
    }

    public function testDryRunReportsTheTargetWithoutErasing(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dry run: nothing was erased.', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    public function testForcedRunErasesAndReports(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Erased subject ' . UserMother::DEFAULT_ID, $tester->getDisplay());
        $this->assertTrue($users->removeCalled);
    }

    public function testAnAlreadyErasedSubjectReportsNothingToErase(): void
    {
        $tester = $this->tester(new InMemoryUserRepository());

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Nothing to erase', $tester->getDisplay());
    }

    private function tester(InMemoryUserRepository $users): CommandTester
    {
        return new CommandTester(new EraseIdentitySubjectCommand(new EraseIdentitySubject(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new RecordingAuditLogger(),
            new InlineTransactionManager(),
        )));
    }
}
