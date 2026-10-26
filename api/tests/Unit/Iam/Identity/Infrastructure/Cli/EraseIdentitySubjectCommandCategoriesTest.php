<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\FulfilIdentityErasureResult;
use Erpify\Iam\Identity\Infrastructure\Cli\EraseIdentitySubjectCommand;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryActiveAdministratorDirectory;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryErasureResweepRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryPasswordResetTokenRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\PasswordResetTokenMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\RecoverySecretMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
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
 * Every console surface besides the consent prompt that names the erasure chain — the help, the "nothing to
 * erase" notice and the success report — holds {@see FulfilIdentityErasureResult::ERASED_CATEGORIES}, label for
 * label. {@see FulfilIdentityErasureResultTest} keeps the map equal to the result's properties; this keeps each
 * surface rendered from the map, so a hand-written sentence going back into any of them goes red here naming
 * the property it dropped. The prompt's own pin lives in {@see EraseIdentitySubjectCommandConfirmationTest}.
 *
 * Displays are compared with their whitespace collapsed, because `SymfonyStyle` wraps a block to the terminal
 * width and a label can straddle the break.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(EraseIdentitySubjectCommand::class)]
final class EraseIdentitySubjectCommandCategoriesTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    private const int RESET_TOKENS = 8;

    private const int AUTHORED_ROWS = 3;

    private const int NAMING_ROWS = 4;

    private const int EVENT_ROWS = 5;

    private const int SESSIONS = 6;

    /** Two, not one: the subject's single recovery secret already reports 1. */
    private const int MEMBERSHIPS = 2;

    private const int INVITATIONS = 7;

    public function testTheHelpNamesEveryCategoryTheResultReports(): void
    {
        $help = $this->collapsed($this->commandFor(new InMemoryUserRepository())->getProcessedHelp());

        foreach (FulfilIdentityErasureResult::ERASED_CATEGORIES as $property => $label) {
            $this->assertStringContainsString(
                $label,
                $help,
                \sprintf('the help must name what `%s` reports', $property),
            );
        }
    }

    public function testTheNothingToEraseNoticeNamesEveryCategoryTheResultReports(): void
    {
        $tester = new CommandTester($this->commandFor(new InMemoryUserRepository()));

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $this->collapsed($tester->getDisplay());
        $this->assertStringContainsString('Nothing to erase', $display);

        foreach (FulfilIdentityErasureResult::ERASED_CATEGORIES as $property => $label) {
            $this->assertStringContainsString(
                $label,
                $display,
                \sprintf('the "nothing to erase" notice must name what `%s` reports', $property),
            );
        }
    }

    /**
     * Every figure is distinct, so a figure printed under the wrong label is a mismatch rather than two equal
     * numbers agreeing by accident; and the labels are asserted in the map's
     * order, which is the one order the report promises.
     */
    public function testTheSuccessReportGivesOneFigurePerCategoryInTheMapsOrder(): void
    {
        $tester = new CommandTester($this->seededCommand());

        $exitCode = $tester->execute(['user-id' => UserMother::DEFAULT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $this->collapsed($tester->getDisplay());
        $this->assertStringContainsString('Erased subject ' . UserMother::DEFAULT_ID, $display);

        $expected = [
            'identityErased' => 'yes',
            'resetTokensDeleted' => (string) self::RESET_TOKENS,
            'recoverySecretsDeleted' => '1',
            'anonymizedAuditRows' => (string) self::AUTHORED_ROWS,
            'anonymizedResourceRows' => (string) self::NAMING_ROWS,
            'anonymizedEventRows' => (string) self::EVENT_ROWS,
            'sessionsDeleted' => (string) self::SESSIONS,
            'membershipsDeleted' => (string) self::MEMBERSHIPS,
            'invitationsDeleted' => (string) self::INVITATIONS,
        ];
        $previous = -1;

        foreach (FulfilIdentityErasureResult::ERASED_CATEGORIES as $property => $label) {
            $figure = \sprintf('%s: %s', $label, $expected[$property]);
            $position = \strpos($display, $figure);

            $this->assertIsInt($position, \sprintf('the success report must give `%s` as "%s"', $property, $figure));
            $this->assertGreaterThan(
                $previous,
                $position,
                \sprintf('the success report must give `%s` in the order of the map', $property),
            );
            $previous = $position;
        }
    }

    private function collapsed(string $text): string
    {
        $collapsed = \preg_replace('/\s+/', ' ', $text);
        $this->assertIsString($collapsed);

        return $collapsed;
    }

    private function seededCommand(): EraseIdentitySubjectCommand
    {
        $tokens = [];

        for ($i = 0; $i < self::RESET_TOKENS; ++$i) {
            $tokens[] = PasswordResetTokenMother::pendingFor();
        }

        $sessions = [];

        for ($i = 0; $i < self::SESSIONS; ++$i) {
            $sessions[] = SessionMother::active(id: Uuid::generate(), userId: UserMother::DEFAULT_ID);
        }

        $memberships = new InMemoryMembershipRepository();

        for ($i = 0; $i < self::MEMBERSHIPS; ++$i) {
            $memberships->save(Membership::grant(Uuid::generate(), UserMother::DEFAULT_ID, Uuid::generate()));
        }

        $invitations = [];

        for ($i = 0; $i < self::INVITATIONS; ++$i) {
            $invitations[] = Invitation::create(
                Uuid::generate(),
                Uuid::generate(),
                UserMother::DEFAULT_ID,
                SingleUseToken::mint(SystemClock::now()->modify('+1 day'))->token,
            );
        }

        return $this->commandFor(
            new InMemoryUserRepository(UserMother::create()),
            new InMemoryPasswordResetTokenRepository(...$tokens),
            new InMemoryRecoverySecretRepository(RecoverySecretMother::mintedFor()),
            new InMemorySessionRepository(...$sessions),
            [self::AUTHORED_ROWS, self::NAMING_ROWS, self::EVENT_ROWS],
            $memberships,
            new InMemoryInvitationRepository(...$invitations),
        );
    }

    /**
     * @param array{int, int, int} $anonymised authored audit rows, audit rows naming the subject, event rows
     */
    private function commandFor(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens = new InMemoryPasswordResetTokenRepository(),
        InMemoryRecoverySecretRepository $secrets = new InMemoryRecoverySecretRepository(),
        InMemorySessionRepository $sessions = new InMemorySessionRepository(),
        array $anonymised = [0, 0, 0],
        InMemoryMembershipRepository $memberships = new InMemoryMembershipRepository(),
        InMemoryInvitationRepository $invitations = new InMemoryInvitationRepository(),
    ): EraseIdentitySubjectCommand {
        return new EraseIdentitySubjectCommand(new FulfilIdentityErasure(
            new EraseIdentitySubject($users, $tokens, $secrets, new InlineTransactionManager()),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: $anonymised[0]),
                new RecordingAuditResourceAnonymiser(matchCount: $anonymised[1]),
            ),
            new RecordingEventStoreSubjectAnonymiser($anonymised[2]),
            new InMemoryActiveAdministratorDirectory([self::OTHER_ADMIN_ID => true]),
            new PurgeUserSessions($sessions),
            new PurgeUserMembership($memberships),
            new PurgeUserInvitations($invitations),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::system()),
            new InlineTransactionManager(),
            new InMemoryErasureResweepRepository(),
        ));
    }
}
