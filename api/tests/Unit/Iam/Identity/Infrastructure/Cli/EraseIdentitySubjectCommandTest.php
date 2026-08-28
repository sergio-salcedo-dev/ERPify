<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Infrastructure\Cli\EraseIdentitySubjectCommand;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryActiveAdministratorDirectory;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditResourceAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditSubjectRowLock;
use Erpify\Tests\Unit\Shared\Event\Infrastructure\Double\RecordingEventStoreSubjectAnonymiser;
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

    public function testErasingAnAdministratorFails(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        // The subject still carries ADMIN: the shared guard rejects the erase until it is demoted. The CLI's
        // `system` actor cannot trip the self-erasure refusal, so off-request this is the guard that binds.
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);
        $tester = $this->tester($users, $directory);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Erasure failed', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    /**
     * An ORDERING pin, not a regression pin: this passes with or without the unattended refusal, because
     * `--force` short-circuits before the confirmation either way. What it fixes in place is that order — the
     * refusal exists to stop a run nobody could answer, never a run that said up front it needed no answer.
     */
    public function testAnUnattendedRunErasesWithForce(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);

        $exitCode = $tester->execute(
            ['user-id' => UserMother::DEFAULT_ID, '--force' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Erased subject ' . UserMother::DEFAULT_ID, $tester->getDisplay());
        $this->assertTrue($users->removeCalled);
    }

    /**
     * An ORDERING pin, not a regression pin: this passes with or without the unattended refusal, because the
     * dry run short-circuits before the confirmation either way. What it fixes in place is that order — the
     * dry run is the one no-op the operator did express, so it keeps its exit code even where no confirmation
     * could be asked for.
     */
    public function testAnUnattendedDryRunStaysSuccessful(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);

        $exitCode = $tester->execute(
            ['user-id' => UserMother::DEFAULT_ID, '--dry-run' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dry run: nothing was erased.', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    private function tester(
        InMemoryUserRepository $users,
        ?InMemoryActiveAdministratorDirectory $directory = null,
    ): CommandTester {
        return new CommandTester($this->commandFor($users, $directory));
    }

    private function commandFor(
        InMemoryUserRepository $users,
        ?InMemoryActiveAdministratorDirectory $directory = null,
    ): EraseIdentitySubjectCommand {
        $audit = new RecordingAuditLogger();

        return new EraseIdentitySubjectCommand(new FulfilIdentityErasure(
            new EraseIdentitySubject(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                new InMemoryRecoverySecretRepository(),
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            new RecordingEventStoreSubjectAnonymiser(),
            $directory ?? new InMemoryActiveAdministratorDirectory([self::OTHER_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            $audit,
            new FixedActorContextFactory(ActorContext::system()),
            new InlineTransactionManager(),
        ));
    }
}
