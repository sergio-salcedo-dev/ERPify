<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Exception\AdministratorErasureRequiresDemotion;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
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
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The order in which the erasure chain acquires its three row locks, kept apart from the behaviour tests of
 * either use case because it is one claim about both and a failure has to say so.
 *
 * The chain is the only path that reaches all three tables, and it is therefore the only path that can be
 * measured against the four that reach two of them. Those four already agree with each other:
 * {@see \Erpify\Iam\Invitation\Application\AcceptInvitation} and
 * {@see \Erpify\Iam\Invitation\Application\RevokeInvitation} take `iam_invitation` before `identity_user`;
 * {@see \Erpify\Iam\Identity\Application\RequestPasswordReset} and {@see CompletePasswordReset} take
 * `identity_user` before `identity_password_reset_token`. Two of them close a cycle with the chain's opposite
 * order, and a cycle in a wait-for graph is `40P01`.
 *
 * **The chain is the member that conforms, and the rule is not a probability estimate.** The order of a shared
 * pair is fixed by whichever path cannot move — and the two cycles pin their order for different reasons,
 * which is worth stating because one rule does not cover both. In the first, an accept arrives holding a token
 * and learns which identity it concerns only after reading the invitation it has already locked: no degrees of
 * freedom at all. In the second, all three participants know both ids up front, and what pins the reset paths
 * is their own correctness — the user row lock is the supersede mutex the forgot path needs before its
 * delete+insert, and the status re-read under it is what walls a completion an administrator has just
 * suspended. {@see FulfilIdentityErasure} is pinned by neither, so it is the one that conforms.
 *
 * What is asserted is therefore a POSITION — the whole sequence — and not that each statement ran. A lock
 * taken second orders nothing that has not already happened, so a test counting calls would pass over the
 * single arrangement the operation exists to forbid. Nothing in the PRE-EXISTING suite goes red on a
 * reordering: the counts in the result, the compliance entries and every anonymiser assertion are all
 * order-blind. Its sibling below is the other half, not a redundancy.
 *
 * What a green proves is that the USE CASES reach for the tables in this order. That the production adapters
 * really take a lock where the doubles record one is a different claim, and its instrument is
 * {@see \Erpify\Tests\Functional\Iam\Identity\ErasureLockOrderFunctionalTest}.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
#[CoversClass(EraseIdentitySubject::class)]
final class ErasureLockOrderTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    #[Test]
    public function theChainAcquiresInTheOrderEveryOtherPathAlreadyAgreesOn(): void
    {
        $journal = new LockOrderJournal();
        $invitations = new InMemoryInvitationRepository($this->invitationFor(UserMother::DEFAULT_ID));
        $tokens = new InMemoryPasswordResetTokenRepository($this->resetTokenFor(UserMother::DEFAULT_ID));
        $users = new InMemoryUserRepository(UserMother::create());

        $result = $this->useCase($users, $tokens, $invitations, $journal)->execute(UserMother::DEFAULT_ID);

        // Not decoration: a statement that matches no row takes no lock, so a chain measured over an empty
        // store would record its sequence and prove nothing about the locks that sequence is about.
        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->invitationsDeleted);
        $this->assertSame(1, $result->resetTokensDeleted);

        $this->assertSame(
            [
                LockOrderJournal::IAM_INVITATION,
                LockOrderJournal::IDENTITY_USER,
                LockOrderJournal::PASSWORD_RESET_TOKEN,
            ],
            $journal->crossTableOrder(),
            'The erasure chain no longer acquires in the order the accept, revoke and reset paths agree on. '
            . 'Swapping any adjacent pair re-opens an ABBA deadlock with a path that cannot reorder itself, '
            . 'and the deadlock surfaces as a 503 with nothing in the code to explain it.',
        );
    }

    #[Test]
    public function anAdministratorIsRefusedBeforeAnyOfTheThreeTablesIsReachedFor(): void
    {
        // The UNLOCKED guard is a precondition and belongs ahead of every acquisition — including the
        // invitation purge, whose position is immediately after it. Moving the purge one statement earlier
        // would take write locks on `iam_invitation` for a transaction about to abort, blocking exactly the
        // accept-versus-revoke pair this ordering exists to protect. The rollback hides the damage; the
        // contention is the cost, and this is what measures it. The guard that DECIDES runs after the purge
        // and cannot run before it — it locks the subject's row, which the chain may not take first — so what
        // this case pins is that the ordinary refusal, the one that fires when the subject was already an
        // administrator, still costs no write lock at all.
        $journal = new LockOrderJournal();
        $invitations = new InMemoryInvitationRepository($this->invitationFor(UserMother::DEFAULT_ID));
        $tokens = new InMemoryPasswordResetTokenRepository($this->resetTokenFor(UserMother::DEFAULT_ID));
        $users = new InMemoryUserRepository(UserMother::create());

        // Caught rather than declared with expectException + finally: on the mutation this exists to catch the
        // finally would throw its own ExpectationFailedException, PHP would discard the refusal it replaced,
        // and PHPUnit would report "not an instance of AdministratorErasureRequiresDemotion" — red for the
        // right reason but naming the wrong one.
        try {
            $this->useCase($users, $tokens, $invitations, $journal, administrators: [
                UserMother::DEFAULT_ID => true,
                self::ACTING_ADMIN_ID => true,
            ])->execute(UserMother::DEFAULT_ID);
            $this->fail('Expected AdministratorErasureRequiresDemotion.');
        } catch (AdministratorErasureRequiresDemotion) {
            // refused inside the transaction, before any of the three tables is reached for
        }

        $this->assertSame([], $journal->tablesLockedInOrder);
    }

    /**
     * Every collaborator that reaches one of the three tables writes to ONE journal, the administrator
     * directory included — its set lock is over `identity_user` rows, so leaving it out would let a caller
     * insert an acquisition on that table ahead of the others and keep the sequence looking untouched.
     *
     * @param array<string, bool> $administrators admin user id => backing user exists AND is ACTIVE. The
     *                                            subject's own absence from it is what lets the erasure past
     *                                            the demotion refusal
     */
    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        InMemoryInvitationRepository $invitations,
        LockOrderJournal $journal,
        array $administrators = [self::ACTING_ADMIN_ID => true],
    ): FulfilIdentityErasure {
        $users->lockOrderJournal = $journal;
        $tokens->lockOrderJournal = $journal;
        $invitations->lockOrderJournal = $journal;
        $administratorDirectory = new InMemoryActiveAdministratorDirectory($administrators);
        $administratorDirectory->lockOrderJournal = $journal;

        return new FulfilIdentityErasure(
            new EraseIdentitySubject($users, $tokens, new InlineTransactionManager()),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            new RecordingEventStoreSubjectAnonymiser(),
            $administratorDirectory,
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations($invitations),
            new RecordingAuditLogger(),
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }

    private function invitationFor(string $userId): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-08-07T13:00:00+00:00'));
        $invitation = Invitation::create(Uuid::generate(), self::ORGANIZATION_ID, $userId, $generated->token);
        $invitation->pullDomainEvents();

        return $invitation;
    }

    private function resetTokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-08-07T13:00:00+00:00'));
        $token = PasswordResetToken::issue(Uuid::generate(), $userId, $generated->token);
        $token->pullDomainEvents();

        return $token;
    }
}
