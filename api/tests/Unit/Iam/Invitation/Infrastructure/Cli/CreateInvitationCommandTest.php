<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Infrastructure\Cli\CreateInvitationCommand;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Invitation\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Invitation\Application\SpyInvitationEmailSender;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Organization\Organization\Application\InMemoryOrganizationRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The command's only decision: whether the raw accept token reaches stdout. It is a credential, and stdout is
 * scrollback, shell history and the log of whatever ran the command — so the default is silence, `--show-token`
 * is the deliberate opt-in, and a send the mailer refused prints it regardless because out-of-band hand-over is
 * then the invitee's only route to the link.
 *
 * Driven over the real {@see SendInvitation} with in-memory collaborators rather than a mocked use case: the
 * use case is `final readonly`, and the branch under test reads a value it produces.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CreateInvitationCommand::class)]
final class CreateInvitationCommandTest extends TestCase
{
    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b80';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    private const string EMAIL = 'newbie@erpify.test';

    #[Test]
    public function itKeepsTheTokenOffStdoutWhenTheMailerAcceptedTheSend(): void
    {
        [$tester, $invitationId] = $this->invite(mailerFails: false, showToken: false);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringNotContainsString($invitationId, $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheTokenWhenAskedEvenThoughTheMailerAcceptedTheSend(): void
    {
        [$tester, $invitationId] = $this->invite(mailerFails: false, showToken: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($invitationId . '.', $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheTokenAndSaysWhyWhenTheMailerRefusedTheSend(): void
    {
        [$tester, $invitationId] = $this->invite(mailerFails: true, showToken: false);

        // Exit 0 is the contract, not an oversight: the invitation committed and is live. Failing here would
        // report a rollback that did not happen.
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($invitationId . '.', $tester->getDisplay());
        $this->assertStringContainsString('could not be sent', $tester->getDisplay());
    }

    #[Test]
    public function itStillPrintsTheTokenOnARefusedSendWhenTheFlagIsAlsoSet(): void
    {
        [$tester, $invitationId] = $this->invite(mailerFails: true, showToken: true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString($invitationId . '.', $tester->getDisplay());
        $this->assertStringContainsString('could not be sent', $tester->getDisplay());
    }

    /**
     * @return array{CommandTester, string}
     */
    private function invite(bool $mailerFails, bool $showToken): array
    {
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save(Organization::provision(self::ORG_ID, 'ACME'));

        $invitations = new InMemoryInvitationRepository();

        $command = new CreateInvitationCommand(new SendInvitation(
            new InviteUser(new InMemoryUserRepository(), $this->passingValidator(), new RecordingAuditLogger()),
            new GrantMembership(new InMemoryMembershipRepository(), $organizations),
            $invitations,
            new SendInvitationEmailBestEffort($this->emailSender($mailerFails), new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        ));

        $tester = new CommandTester($command);
        $tester->execute(['email' => self::EMAIL, 'roles' => [], '--show-token' => $showToken]);

        $this->assertCount(1, $invitations->saved);
        $invitationId = $invitations->saved[0]->getId();
        $this->assertNotNull($invitationId);

        return [$tester, $invitationId];
    }

    private function emailSender(bool $fails): InvitationEmailSender
    {
        if (!$fails) {
            return new SpyInvitationEmailSender();
        }

        $failing = $this->createStub(InvitationEmailSender::class);
        $failing->method('send')->willThrowException(new RuntimeException('mailer down'));

        return $failing;
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
