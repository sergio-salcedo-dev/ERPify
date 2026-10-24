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
use Erpify\Iam\Invitation\Domain\Exception\ResendableInvitationNotFound;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli\CapturingInvitationEmailSender;
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

    private const string SECOND_INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b93';

    private const string FOREIGN_INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b94';

    private const string OTHER_USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b97';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b92';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    #[Test]
    public function itReissuesAFreshTokenInvalidatesTheOldOneEmailsItAndPublishes(): void
    {
        [$invitation, $oldSecret] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        $reissued = $this->useCase($invitations, $users, $emailSender, $eventBus)->resend(self::INVITATION_ID);
        $newToken = $reissued->acceptToken;

        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertFalse($invitation->verify($oldSecret, new DateTimeImmutable(self::NOW)));
        $this->assertStringStartsWith(self::INVITATION_ID . '.', $newToken);
        $this->assertTrue($invitation->verify($this->secretOf($newToken), new DateTimeImmutable(self::NOW)));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationResent::class, $eventBus->publishedEvents[0]);
        $this->assertCount(1, $emailSender->sent);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $emailSender->sent[0]['recipient']);
        $this->assertSame($newToken, $emailSender->sent[0]['token']);
        $this->assertTrue($reissued->mailerAccepted);
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

    #[Test]
    public function itReissuesThePendingInvitationOfAUserByTheUserId(): void
    {
        [$invitation, $oldSecret] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        $issued = $this->useCase($invitations, $users, $emailSender, $eventBus)
            ->resendForInvitedUser(UserMother::DEFAULT_ID)
        ;

        $this->assertCount(1, $issued);
        // The selector is the invitation's own id, never the user id the caller passed: the accept flow
        // selects the row by it, so a token built on the wrong id would only ever meet the opaque wall.
        $this->assertStringStartsWith(self::INVITATION_ID . '.', $issued[0]->acceptToken);
        $this->assertTrue($issued[0]->mailerAccepted);
        $this->assertFalse($invitation->verify($oldSecret, new DateTimeImmutable(self::NOW)));
        $this->assertTrue(
            $invitation->verify($this->secretOf($issued[0]->acceptToken), new DateTimeImmutable(self::NOW)),
        );
        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationResent::class, $eventBus->publishedEvents[0]);
        $this->assertSame(
            [['recipient' => UserMother::DEFAULT_EMAIL, 'token' => $issued[0]->acceptToken]],
            $emailSender->sent,
        );
    }

    #[Test]
    public function itReissuesEveryPendingInvitationOfAUserInOneTransaction(): void
    {
        [$first] = $this->sentInvitation();
        [$second] = $this->sentInvitation(self::SECOND_INVITATION_ID);
        // Somebody else's invitation in the same store: the predicate has to be the invitee.
        [$foreign, $foreignSecret] = $this->sentInvitation(self::FOREIGN_INVITATION_ID, self::OTHER_USER_ID);
        $invitations = new InMemoryInvitationRepository($first, $second, $foreign);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));
        $emailSender = new SpyInvitationEmailSender();
        $transactions = new CountingTransactionManager();

        $issued = $this->useCase($invitations, $users, $emailSender, new RecordingEventBus(), $transactions)
            ->resendForInvitedUser(UserMother::DEFAULT_ID)
        ;

        $this->assertCount(2, $issued);
        $this->assertCount(2, $emailSender->sent);
        $this->assertSame(1, $transactions->committed);
        $this->assertTrue($foreign->verify($foreignSecret, new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function itReportsARefusedSendWithoutLosingTheCommittedReissue(): void
    {
        [$invitation, $oldSecret] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));

        $issued = (new ResendInvitation(
            $invitations,
            $users,
            new SendInvitationEmailBestEffort(CapturingInvitationEmailSender::refusing(), new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        ))->resendForInvitedUser(UserMother::DEFAULT_ID);

        // The send runs after the commit, so its failure cannot undo the reissue: the old link is dead either
        // way, which is exactly why the caller has to be told rather than left to assume delivery.
        $this->assertCount(1, $issued);
        $this->assertFalse($issued[0]->mailerAccepted);
        $this->assertFalse($invitation->verify($oldSecret, new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function itTakesTheRowLocksBeforeReissuingByTheUserId(): void
    {
        [$invitation] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));

        $this->useCase($invitations, $users, new SpyInvitationEmailSender(), new RecordingEventBus())
            ->resendForInvitedUser(UserMother::DEFAULT_ID)
        ;

        // The user-keyed read is the locked one by construction; what is pinned here is that the use case
        // never falls back to the unlocked by-id lookup on the way.
        $this->assertSame([], $invitations->unlockedReads);
    }

    #[Test]
    public function itRejectsAUserWithNothingPendingAsNotFoundAndMailsNothing(): void
    {
        // A spent invitation is not resendable, so a user holding only accepted ones is indistinguishable from
        // one holding none — the console's cue that the row is already in its final state.
        [$accepted] = $this->sentInvitation();
        $accepted->accept();
        $accepted->pullDomainEvents();

        $emailSender = new SpyInvitationEmailSender();

        try {
            $this->useCase(
                new InMemoryInvitationRepository($accepted),
                new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID)),
                $emailSender,
                new RecordingEventBus(),
            )->resendForInvitedUser(UserMother::DEFAULT_ID);
            $this->fail('Expected ResendableInvitationNotFound.');
        } catch (ResendableInvitationNotFound $resendableInvitationNotFound) {
            $this->assertSame('resendable-invitation-not-found', $resendableInvitationNotFound->type());
            $this->assertSame(['userId' => UserMother::DEFAULT_ID], $resendableInvitationNotFound->context());
        }

        $this->assertSame([], $emailSender->sent);
    }

    #[Test]
    public function itRejectsAWithdrawnIdentityAsNotFoundAndWritesNothing(): void
    {
        // A `SENT` row whose identity is no longer pending has nothing a console could re-deliver: the link
        // would only ever meet the opaque wall. By user id that is "no pending invitation", not a server fault.
        [$invitation] = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $emailSender = new SpyInvitationEmailSender();
        $eventBus = new RecordingEventBus();

        try {
            $this->useCase(
                $invitations,
                new InMemoryUserRepository(UserMother::revoked(UserMother::DEFAULT_ID)),
                $emailSender,
                $eventBus,
            )->resendForInvitedUser(UserMother::DEFAULT_ID);
            $this->fail('Expected ResendableInvitationNotFound.');
        } catch (ResendableInvitationNotFound) {
            // Asserted below: the refusal is worth little if a fresh token was already written or mailed.
        }

        $this->assertSame([], $invitations->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $emailSender->sent);
    }

    private function useCase(
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        SpyInvitationEmailSender $emailSender,
        RecordingEventBus $eventBus,
        ?CountingTransactionManager $transactions = null,
    ): ResendInvitation {
        return new ResendInvitation(
            $invitations,
            $users,
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            $eventBus,
            $transactions ?? new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @return array{0: Invitation, 1: string} the SENT invitation and the ORIGINAL secret
     */
    private function sentInvitation(
        string $id = self::INVITATION_ID,
        string $userId = UserMother::DEFAULT_ID,
    ): array {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create($id, self::ORG_ID, $userId, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return [$invitation, $generated->plaintext()];
    }

    private function secretOf(string $token): string
    {
        return \substr($token, \strpos($token, '.') + 1);
    }
}
