<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Infrastructure\Cli\ResendInvitationCommand;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Invitation\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\RecordingEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The resend command makes the same decision about the raw accept token as its create sibling, and needs its
 * own coverage rather than inheriting it: nothing in the suite would otherwise go red if this command alone
 * printed unconditionally.
 *
 * A refused send matters more here than on create, because a resend has already invalidated the previous
 * digest — the invitee's old link is dead whether or not the new one was delivered.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResendInvitationCommand::class)]
final class ResendInvitationCommandTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b95';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b96';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function itKeepsTheTokenOffStdoutWhenTheMailerAcceptedTheSend(): void
    {
        [$tester, $emailSender] = $this->resend(mailerFails: false, showToken: false);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringNotContainsString($emailSender->capturedToken(), $tester->getDisplay());
        $this->assertStringNotContainsString($emailSender->capturedSecret(), $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheTokenWhenAskedEvenThoughTheMailerAcceptedTheSend(): void
    {
        [$tester, $emailSender] = $this->resend(mailerFails: false, showToken: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($emailSender->capturedToken(), $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheTokenAndSaysWhyWhenTheMailerRefusedTheSend(): void
    {
        [$tester, $emailSender] = $this->resend(mailerFails: true, showToken: false);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($emailSender->capturedToken(), $tester->getDisplay());
        $this->assertStringContainsString('could not be sent', $tester->getDisplay());
    }

    #[Test]
    public function itStillPrintsTheTokenOnARefusedSendWhenTheFlagIsAlsoSet(): void
    {
        [$tester, $emailSender] = $this->resend(mailerFails: true, showToken: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($emailSender->capturedToken(), $tester->getDisplay());
        $this->assertStringContainsString('could not be sent', $tester->getDisplay());
    }

    /**
     * @return array{CommandTester, CapturingInvitationEmailSender}
     */
    private function resend(bool $mailerFails, bool $showToken): array
    {
        $emailSender = $mailerFails
            ? CapturingInvitationEmailSender::refusing()
            : CapturingInvitationEmailSender::accepting();

        $command = new ResendInvitationCommand(new ResendInvitation(
            new InMemoryInvitationRepository($this->sentInvitation()),
            new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID)),
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        ));

        $tester = new CommandTester($command);
        $tester->execute(['invitationId' => self::INVITATION_ID, '--show-token' => $showToken]);

        return [$tester, $emailSender];
    }

    private function sentInvitation(): Invitation
    {
        $invitation = Invitation::create(
            self::INVITATION_ID,
            self::ORG_ID,
            UserMother::DEFAULT_ID,
            SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'))->token,
        );
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
