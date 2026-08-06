<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Event\UserInvitationRevoked;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Invitation\Application\RevokeInvitation;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The other half of the revocation: pulling the delivery record is not enough, because the identity it
 * provisioned would otherwise sit at `INVITED` — carrying the roles that were being granted, `ADMIN` included —
 * for as long as the installation lives, and the register would keep offering to revoke what is already gone.
 *
 * Kept apart from {@see RevokeInvitationTest}, which is about the invitation *set*, so neither class has to
 * carry both vocabularies.
 *
 * A revocation spans two aggregates, so a test of it necessarily names both vocabularies: the invitation one
 * that sets the scene and the identity one under assertion. The coupling is that span, not a class doing
 * several jobs.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RevokeInvitation::class)]
final class RevokeInvitationIdentityWithdrawalTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b80';

    private const string SECOND_INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b83';

    private const string USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b81';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b82';

    #[Test]
    public function itWithdrawsTheIdentityTheCliRevocationLeavesBehind(): void
    {
        $user = $this->invitedUser();
        $users = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();

        $this->revoker(new InMemoryInvitationRepository($this->sentInvitation()), $users, $eventBus)
            ->revoke(self::INVITATION_ID)
        ;

        $this->assertSame(IdentityStatus::REVOKED, $user->status());
        $this->assertSame([$user], $users->saved);
        $this->assertCount(1, $this->withdrawalsIn($eventBus));
    }

    #[Test]
    public function itWithdrawsTheIdentityOnceHoweverManyInvitationsArePulled(): void
    {
        $user = $this->invitedUser();
        $users = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $invitations = new InMemoryInvitationRepository(
            $this->sentInvitation(),
            $this->sentInvitation(self::SECOND_INVITATION_ID),
        );

        $this->revoker($invitations, $users, $eventBus)->revokeForInvitedUser(self::USER_ID);

        $this->assertSame(IdentityStatus::REVOKED, $user->status());
        // The transition is guarded INVITED -> REVOKED, so applying it per invitation would raise an invalid
        // transition on the second and abort a revocation that had already succeeded.
        $this->assertCount(1, $this->withdrawalsIn($eventBus));
        $this->assertCount(1, $users->saved);
        // The row is locked for the same reason the invitations are: an accept landing first must lose the
        // race deterministically rather than leave the pair disagreeing.
        $this->assertSame([self::USER_ID], $users->forUpdateCalls);
    }

    #[Test]
    public function itSurfacesAnIdentityThatVanishedUnderTheLock(): void
    {
        $users = new InMemoryUserRepository($this->invitedUser());
        $users->goneUnderLock = true;

        $this->expectException(UserNotFound::class);

        $this->revoker(new InMemoryInvitationRepository($this->sentInvitation()), $users, new RecordingEventBus())
            ->revokeForInvitedUser(self::USER_ID)
        ;
    }

    /**
     * @return list<UserInvitationRevoked>
     */
    private function withdrawalsIn(RecordingEventBus $eventBus): array
    {
        return \array_values(\array_filter(
            $eventBus->publishedEvents,
            static fn (object $event): bool => $event instanceof UserInvitationRevoked,
        ));
    }

    private function revoker(
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        RecordingEventBus $eventBus,
    ): RevokeInvitation {
        return new RevokeInvitation($invitations, $users, $eventBus, new CountingTransactionManager());
    }

    private function invitedUser(): User
    {
        return User::invite(self::USER_ID, 'invitee@erpify.test');
    }

    private function sentInvitation(string $id = self::INVITATION_ID): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create($id, self::ORG_ID, self::USER_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
