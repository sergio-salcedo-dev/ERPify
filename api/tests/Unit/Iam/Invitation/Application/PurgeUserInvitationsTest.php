<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The published seam a GDPR erasure consumes to drop every delivery record addressed to a subject. It hard
 * deletes each of them whatever their lifecycle state, scoped to that user, and guards the id shape before
 * the store is touched at all.
 *
 * @internal
 */
#[CoversClass(PurgeUserInvitations::class)]
final class PurgeUserInvitationsTest extends TestCase
{
    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    public function testHardDeletesEveryInvitationOfTheUserWhateverItsStateAndReturnsTheCount(): void
    {
        $userId = Uuid::generate();
        $other = Uuid::generate();
        // A retired invitation still carries the invited person's id, so the terminal states are exactly as
        // much of a residue as the pending one — sparing them would leave the reference alive.
        $accepted = $this->invitationFor($userId);
        $accepted->markSent();
        $accepted->accept();

        $revoked = $this->invitationFor($userId);
        $revoked->markSent();
        $revoked->revoke();

        $invitations = new InMemoryInvitationRepository(
            $this->invitationFor($userId),
            $accepted,
            $revoked,
            $this->invitationFor($other),
        );

        $deleted = (new PurgeUserInvitations($invitations))->purge($userId);

        $this->assertSame(3, $deleted);
        $this->assertSame(1, $invitations->deleteAllForInvitedUser($other));
    }

    public function testIsIdempotentForAUserWithNoInvitation(): void
    {
        $invitations = new InMemoryInvitationRepository();

        $this->assertSame(0, (new PurgeUserInvitations($invitations))->purge(Uuid::generate()));
    }

    public function testRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $invitations = new InMemoryInvitationRepository($this->invitationFor(Uuid::generate()));

        $this->expectException(InvalidUuidException::class);

        (new PurgeUserInvitations($invitations))->purge('not-a-uuid');
    }

    private function invitationFor(string $userId): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-21T13:00:00+00:00'));
        $invitation = Invitation::create(Uuid::generate(), self::ORGANIZATION_ID, $userId, $generated->token);
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
