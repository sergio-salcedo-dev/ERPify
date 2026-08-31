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
use Erpify\Tests\Unit\Shared\Console\Double\DrainedInputStream;
use Erpify\Tests\Unit\Shared\Event\Infrastructure\Double\RecordingEventStoreSubjectAnonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whether the operator was asked, and what the command does with the answer — kept apart from the cases that
 * exercise the erasure itself. The three refusals differ in mechanism: the flag says the run is unattended,
 * the question helper demotes the input while answering with its default, and a stream a previous read
 * exhausted never lets the helper raise at all.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(EraseIdentitySubjectCommand::class)]
final class EraseIdentitySubjectCommandConfirmationTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    public function testDecliningTheConfirmationErasesNothing(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);
        $tester->setInputs(['no']);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    /**
     * The operator was asked and never answered, so success would be a lie a compliance job cannot see
     * through: it reads `$?`, and a `0` from an erasure that did nothing is indistinguishable from a `0`
     * from one that erased everything.
     */
    public function testAnUnattendedRunWithoutForceRefusesInsteadOfReportingSuccess(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $exitCode);
        // Single tokens: the refusal is rendered as a SymfonyStyle error block, which word-wraps to the
        // terminal width, so any multi-word phrase can straddle a line break the assertion cannot see.
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    /**
     * A separate path from --no-interaction: the input is still interactive when the question is put, and
     * the question helper answers it with the default rather than raising. Left unread, that default is the
     * abort branch above — the same silent `0` over an erasure nobody declined.
     */
    public function testAConfirmationNobodyCanAnswerRefusesInsteadOfReportingSuccess(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tester = $this->tester($users);
        $tester->setInputs([]);

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID]);

        $this->assertSame(Command::INVALID, $exitCode);
        // Single tokens: the refusal is rendered as a SymfonyStyle error block, which word-wraps to the
        // terminal width, so any multi-word phrase can straddle a line break the assertion cannot see.
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertFalse($users->removeCalled);
    }

    /**
     * The stdin case neither other guard reaches. `QuestionHelper::doReadInput()` loops
     * `while (!feof($inputStream))`, so a stream a previous read already exhausted never enters the loop and
     * never raises the `MissingInputException` the post-`confirm()` re-read depends on — the default is taken
     * as an operator's answer and the run exits `0` having erased nothing. Reachable through the console's
     * own single-alternative prompt, which drains a pipe whose last byte is not a newline.
     *
     * Driven through `Command::run()` rather than `CommandTester`, because the tester always mints a fresh
     * stream and there is no way to hand it a drained one.
     */
    public function testAConfirmationOnAnAlreadyDrainedStreamRefusesInsteadOfReportingSuccess(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $command = $this->commandFor($users);

        $input = new ArrayInput(['user-id' => UserMother::DEFAULT_ID], $command->getDefinition());
        $input->setInteractive(true);
        $input->setStream(DrainedInputStream::open());

        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $output->fetch());
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
