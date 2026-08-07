<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reissuing a live invitation with a fresh token, invalidating the previous one and re-delivering it. The
 * coupling reflects driving the real flow (invitation aggregate + events + identity lookup + email) with
 * concrete collaborators.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResendInvitation::class)]
final class ResendInvitationTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b90';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b92';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function itReissuesAFreshTokenInvalidatesTheOldOneEmailsItAndPublishes(): void
    {
        [$invitation, $oldSecret] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        $newToken = $this->useCase($invitations, $users, $emailSender, $eventBus)->resend(self::INVITATION_ID);

        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertFalse($invitation->verify($oldSecret, new DateTimeImmutable(self::NOW)));
        $this->assertStringStartsWith(self::INVITATION_ID . '.', $newToken);
        $this->assertTrue($invitation->verify($this->secretOf($newToken), new DateTimeImmutable(self::NOW)));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationResent::class, $eventBus->publishedEvents[0]);
        $this->assertCount(1, $emailSender->sent);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $emailSender->sent[0]['recipient']);
        $this->assertSame($newToken, $emailSender->sent[0]['token']);
    }

    #[Test]
    public function itRefusesToReissueAgainstAWithdrawnIdentityAndWritesNothing(): void
    {
        // Reachable, not hypothetical: revoking one of several invitations addressed to the same user
        // withdraws the identity once and leaves the others `SENT`, so the aggregate's own guard passes over a
        // row whose invitee can never activate. Re-tokenising it would mail a link that only ever meets the
        // opaque wall.
        [$invitation] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::revoked(UserMother::DEFAULT_ID));
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        try {
            $this->useCase($invitations, $users, $emailSender, $eventBus)->resend(self::INVITATION_ID);
            $this->fail('Expected the withdrawn identity to refuse the reissue.');
        } catch (InvitedIdentityUnavailable) {
            // Asserted below rather than by the expectation alone: the refusal is worth little if the fresh
            // token was already persisted or mailed before it was raised.
        }

        $this->assertSame([], $invitations->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $emailSender->sent);
    }

    #[Test]
    public function itTakesTheRowLockBeforeReissuing(): void
    {
        [$invitation] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));

        $this->useCase($invitations, $users, new SpyInvitationEmailSender(), new RecordingEventBus())
            ->resend(self::INVITATION_ID)
        ;

        $this->assertSame([self::INVITATION_ID], $invitations->lockedReads);
        $this->assertSame([], $invitations->unlockedReads);
    }

    #[Test]
    public function itRejectsAMissingInvitationAsNotFound(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $users = new InMemoryUserRepository();

        $this->expectException(InvitationNotFound::class);

        $this->useCase($invitations, $users, new SpyInvitationEmailSender(), new RecordingEventBus())
            ->resend(self::INVITATION_ID)
        ;
    }

    private function useCase(
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        SpyInvitationEmailSender $emailSender,
        RecordingEventBus $eventBus,
    ): ResendInvitation {
        return new ResendInvitation(
            $invitations,
            $users,
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @return array{0: Invitation, 1: string} the SENT invitation and the ORIGINAL secret
     */
    private function sentInvitation(): array
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, UserMother::DEFAULT_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return [$invitation, $generated->plaintext()];
    }

    private function secretOf(string $token): string
    {
        return \substr($token, \strpos($token, '.') + 1);
    }
}
