<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Infrastructure\Cli\EraseIdentitySubjectCommand;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryActiveAdministratorDirectory;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI shares the chained {@see FulfilIdentityErasure} use case with the identity console, so it erases the
 * identity, anonymises the trail and drops the sessions in one operation and enforces the ≥1-active-admin guard.
 * It runs as the `system` actor (no id), so the self-erasure refusal never applies here.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(EraseIdentitySubjectCommand::class)]
final class EraseIdentitySubjectCommandTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

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

    public function testErasingTheLastActiveAdministratorFails(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        // The subject is the sole active administrator: the shared guard rejects the erase.
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);
        $tester = $this->tester($users, $directory);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Erasure failed', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    private function tester(
        InMemoryUserRepository $users,
        ?InMemoryActiveAdministratorDirectory $directory = null,
    ): CommandTester {
        $audit = new RecordingAuditLogger();

        return new CommandTester(new EraseIdentitySubjectCommand(new FulfilIdentityErasure(
            new EraseIdentitySubject(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                $audit,
                new InlineTransactionManager(),
            ),
            new RecordingAuditActorAnonymiser(matchCount: 0),
            $directory ?? new InMemoryActiveAdministratorDirectory([self::OTHER_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            $audit,
            new FixedActorContextFactory(ActorContext::system()),
            new InlineTransactionManager(),
        )));
    }
}
