<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Invitation\Application\RevokeInvitation;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Iam\Invitation\Domain\Exception\RevocableInvitationNotFound;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The invitation-id path is the CLI's; the user-id path is the console's, and it carries the harder contract —
 * nothing constrains a user to one invitation, so it revokes the whole live set at once and must do so
 * atomically. Both halves are driven here over the same in-memory store, and both through
 * {@see CountingTransactionManager}: it runs the unit of work inline like its plain sibling, so using it
 * everywhere costs the older cases nothing and keeps one transaction double in play instead of two.
 *
 * A revocation spans two aggregates, so a test of it necessarily names both vocabularies: the invitation set,
 * its store and its events, plus the identity store the use case collaborates with. The coupling is that span,
 * not a class doing several jobs.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RevokeInvitation::class)]
final class RevokeInvitationTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b80';

    private const string SECOND_INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b83';

    private const string USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b81';

    private const string OTHER_USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b84';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b82';

    #[Test]
    public function itRevokesALiveInvitationAndPublishes(): void
    {
        $invitation = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $eventBus = new RecordingEventBus();

        $this->revoker($invitations, $eventBus)->revoke(self::INVITATION_ID);

        $this->assertSame(InvitationStatus::REVOKED, $invitation->status());
        $this->assertSame([$invitation], $invitations->saved);
        $this->assertCount(2, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationRevoked::class, $eventBus->publishedEvents[0]);
    }

    #[Test]
    public function itRejectsAMissingInvitationAsNotFound(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $eventBus = new RecordingEventBus();

        $this->expectException(InvitationNotFound::class);

        $this->revoker($invitations, $eventBus)->revoke(self::INVITATION_ID);
    }

    #[Test]
    public function itRevokesEveryLiveInvitationOfAUserInOneTransaction(): void
    {
        $first = $this->sentInvitation();
        $second = $this->sentInvitation(self::SECOND_INVITATION_ID);
        // A third invitation, for somebody else, that the same store holds: the predicate has to be the
        // invitee, not "everything pending".
        $foreign = $this->sentInvitation('0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b85', self::OTHER_USER_ID);

        $invitations = new InMemoryInvitationRepository($first, $second, $foreign);
        $eventBus = new RecordingEventBus();
        $transactions = new CountingTransactionManager();

        $this->revoker($invitations, $eventBus, $transactions)->revokeForInvitedUser(self::USER_ID);

        $this->assertSame(InvitationStatus::REVOKED, $first->status());
        $this->assertSame(InvitationStatus::REVOKED, $second->status());
        $this->assertSame(InvitationStatus::SENT, $foreign->status());
        $this->assertCount(3, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationRevoked::class, $eventBus->publishedEvents[0]);
        // Refusing on N>1 would be the worst answer under incident response — it would leave the extra tokens
        // live — and revoking them across several transactions would admit a partially-pulled set.
        $this->assertSame(1, $transactions->opened);
        $this->assertSame(1, $transactions->committed);
    }

    #[Test]
    public function itLeavesNothingRevokedWhenAnyInvitationOfTheSetFails(): void
    {
        $first = $this->sentInvitation();
        $second = $this->sentInvitation(self::SECOND_INVITATION_ID);
        $invitations = new InMemoryInvitationRepository($first, $second);
        $eventBus = new RecordingEventBus();
        $transactions = new CountingTransactionManager();
        $invitations->onSave = static function (Invitation $written): void {
            if (self::SECOND_INVITATION_ID === $written->getId()) {
                throw new RuntimeException('the store refused the second write');
            }
        };

        try {
            $this->revoker($invitations, $eventBus, $transactions)->revokeForInvitedUser(self::USER_ID);
            $this->fail('Expected the store failure to propagate.');
        } catch (RuntimeException) {
            // all-or-nothing: the failure must leave the boundary rather than be absorbed per invitation
        }

        // No in-memory store undoes a write the way Postgres does, so what is asserted is the shape that
        // makes the real rollback total: ONE boundary around every write, abandoned rather than committed.
        // A per-invitation transaction would show `opened === 2` and `committed === 1` here.
        $this->assertSame(1, $transactions->opened);
        $this->assertSame(0, $transactions->committed);
        $this->assertSame(1, $transactions->abandoned);
    }

    #[Test]
    public function itRejectsAUserWithNothingRevocableAsNotFound(): void
    {
        // Terminal invitations are not revocable, so a user holding only spent ones is indistinguishable from
        // one holding none — the console's cue that the row is already in its final state.
        $accepted = $this->sentInvitation();
        $accepted->accept();
        $accepted->pullDomainEvents();

        $invitations = new InMemoryInvitationRepository($accepted);
        $eventBus = new RecordingEventBus();

        $this->expectException(RevocableInvitationNotFound::class);

        $this->revoker($invitations, $eventBus)->revokeForInvitedUser(self::USER_ID);
    }

    private function revoker(
        InMemoryInvitationRepository $invitations,
        RecordingEventBus $eventBus,
        ?CountingTransactionManager $transactions = null,
    ): RevokeInvitation {
        return new RevokeInvitation(
            $invitations,
            $this->invitedUserStore(),
            $eventBus,
            $transactions ?? new CountingTransactionManager(),
        );
    }

    /**
     * The identity every invitation here was minted for. Its withdrawal is asserted in
     * {@see RevokeInvitationIdentityWithdrawalTest}; this store exists so the collaborator is satisfied
     * without pulling the identity vocabulary into the cases that are about the invitation set.
     */
    private function invitedUserStore(): InMemoryUserRepository
    {
        return new InMemoryUserRepository(User::invite(self::USER_ID, 'invitee@erpify.test'));
    }

    private function sentInvitation(string $id = self::INVITATION_ID, string $userId = self::USER_ID): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create($id, self::ORG_ID, $userId, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
