<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Application\RevokeInvitation;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Exception\InvitationNotFound;
use Erpify\Shared\Token\Domain\SingleUseToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RevokeInvitation::class)]
final class RevokeInvitationTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b80';

    private const string USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b81';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b82';

    #[Test]
    public function itRevokesALiveInvitationAndPublishes(): void
    {
        $invitation = $this->sentInvitation();
        $invitations = new InMemoryInvitationRepository($invitation);
        $eventBus = new RecordingEventBus();

        (new RevokeInvitation($invitations, $eventBus, new InlineTransactionManager()))->revoke(self::INVITATION_ID);

        $this->assertSame(InvitationStatus::REVOKED, $invitation->status());
        $this->assertSame([$invitation], $invitations->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationRevoked::class, $eventBus->publishedEvents[0]);
    }

    #[Test]
    public function itRejectsAMissingInvitationAsNotFound(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $eventBus = new RecordingEventBus();

        $this->expectException(InvitationNotFound::class);

        (new RevokeInvitation($invitations, $eventBus, new InlineTransactionManager()))->revoke(self::INVITATION_ID);
    }

    private function sentInvitation(): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
