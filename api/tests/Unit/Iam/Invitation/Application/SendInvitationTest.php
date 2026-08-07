<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Organization\Organization\Application\InMemoryOrganizationRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The invite orchestrator provisions the INVITED identity, funnels it through {@see GrantMembership}, mints
 * the SENT invitation and delivers the raw token by email — atomically. Driven with the real Identity/Organization
 * use cases over in-memory repositories so the funnel is exercised end to end, not mocked away.
 *
 * The high object coupling is inherent to driving that real three-context funnel (Identity + Organization +
 * Invitation) with concrete collaborators instead of mocks.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SendInvitation::class)]
final class SendInvitationTest extends TestCase
{
    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b70';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function itInvitesFunnelsThroughMembershipMintsTheInvitationAndEmailsTheToken(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $organizations->save(Organization::provision(self::ORG_ID, 'ACME'));

        $memberships = new InMemoryMembershipRepository();
        $users = new InMemoryUserRepository();
        $invitations = new InMemoryInvitationRepository();
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        $sendInvitation = new SendInvitation(
            new InviteUser($users, $this->passingValidator(), new RecordingAuditLogger()),
            new GrantMembership($memberships, $organizations),
            $invitations,
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );

        $issued = $sendInvitation->invite('Newbie@Erpify.Test', Role::EDITOR);

        $this->assertCount(1, $users->saved);
        $invitedUser = $users->saved[0];
        $this->assertSame(IdentityStatus::INVITED, $invitedUser->status());
        $this->assertNotInstanceOf(\Erpify\Iam\Identity\Domain\HashedPassword::class, $invitedUser->passwordHash());

        $this->assertCount(1, $memberships->saved);
        $this->assertSame($invitedUser->getId(), $memberships->saved[0]->userId());
        $this->assertSame([Role::EDITOR], $memberships->saved[0]->roles());

        $this->assertCount(1, $invitations->saved);
        $invitation = $invitations->saved[0];
        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertSame($invitedUser->getId(), $invitation->invitedUserId());
        $this->assertSame(self::ORG_ID, $invitation->organizationId());

        $this->assertCount(2, $eventBus->publishedEvents);

        $this->assertCount(1, $emailSender->sent);
        $this->assertSame('newbie@erpify.test', $emailSender->sent[0]['recipient']);
        $this->assertSame($issued->acceptToken, $emailSender->sent[0]['token']);
        $this->assertStringStartsWith($invitation->getId() . '.', $issued->acceptToken);
        $this->assertTrue($issued->mailerAccepted);
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
