<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Event\InvitationCreated;
use Erpify\Iam\Invitation\Domain\Event\InvitationExpired;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Event\InvitationSent;
use Erpify\Iam\Invitation\Domain\Exception\InvalidInvitationTransition;
use Erpify\Shared\Token\Domain\GeneratedToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The invitation lifecycle machine: `CREATED → SENT → ACCEPTED|REVOKED|EXPIRED`, each transition guarded and
 * event-recording; illegal transitions raise {@see InvalidInvitationTransition}. {@see Invitation::verify()} is
 * opaque — a wrong secret and a lapsed TTL both fail as false.
 *
 * @internal
 */
#[CoversClass(Invitation::class)]
final class InvitationTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c00';

    private const string USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c01';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5c02';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    #[Test]
    public function itIsMintedInCreatedRecordingInvitationCreated(): void
    {
        $invitation = $this->create();

        $this->assertSame(InvitationStatus::CREATED, $invitation->status());
        $this->assertSame(self::USER_ID, $invitation->invitedUserId());
        $this->assertSame(self::ORG_ID, $invitation->organizationId());

        $events = $invitation->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(InvitationCreated::class, $events[0]);
        $this->assertSame(self::USER_ID, $events[0]->invitedUserId());
    }

    #[Test]
    public function itIsSentFromCreatedRecordingInvitationSent(): void
    {
        $invitation = $this->create();
        $invitation->pullDomainEvents();

        $invitation->markSent();

        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertRecordedExactly($invitation, InvitationSent::class);
    }

    #[Test]
    public function itIsAcceptedFromSentRecordingInvitationAccepted(): void
    {
        $invitation = $this->sent();

        $invitation->accept();

        $this->assertSame(InvitationStatus::ACCEPTED, $invitation->status());
        $this->assertRecordedExactly($invitation, InvitationAccepted::class);
    }

    #[Test]
    public function itIsRevokedFromSentRecordingInvitationRevoked(): void
    {
        $invitation = $this->sent();

        $invitation->revoke();

        $this->assertSame(InvitationStatus::REVOKED, $invitation->status());
        $this->assertRecordedExactly($invitation, InvitationRevoked::class);
    }

    #[Test]
    public function itIsExpiredFromSentRecordingInvitationExpired(): void
    {
        $invitation = $this->sent();

        $invitation->expire();

        $this->assertSame(InvitationStatus::EXPIRED, $invitation->status());
        $this->assertRecordedExactly($invitation, InvitationExpired::class);
    }

    #[Test]
    public function itIsResentWithAFreshTokenStayingSentAndInvalidatingTheOld(): void
    {
        $old = SingleUseToken::mint($this->future());
        $invitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $old->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        $fresh = SingleUseToken::mint($this->future());
        $invitation->resend($fresh->token);

        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertFalse($invitation->verify($old->plaintext(), $this->now()));
        $this->assertTrue($invitation->verify($fresh->plaintext(), $this->now()));
        $this->assertRecordedExactly($invitation, InvitationResent::class);
    }

    #[Test]
    public function itRejectsIllegalTransitions(): void
    {
        $this->assertRejects($this->create(), static function (Invitation $i): void {
            $i->accept();
        });
        $this->assertRejects($this->sent(), static function (Invitation $i): void {
            $i->markSent();
        });
        $this->assertRejects($this->accepted(), static function (Invitation $i): void {
            $i->revoke();
        });
    }

    #[Test]
    public function verifyIsOpaqueToAWrongSecretAndAnExpiredTtl(): void
    {
        $valid = SingleUseToken::mint($this->future());
        $invitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $valid->token);

        $this->assertTrue($invitation->verify($valid->plaintext(), $this->now()));
        $this->assertFalse($invitation->verify('the-wrong-secret', $this->now()));

        $expiredToken = SingleUseToken::mint($this->past());
        $expiredInvitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $expiredToken->token);
        $this->assertFalse($expiredInvitation->verify($expiredToken->plaintext(), $this->now()));
    }

    /**
     * @param callable(Invitation): void $illegalTransition
     */
    private function assertRejects(Invitation $invitation, callable $illegalTransition): void
    {
        try {
            $illegalTransition($invitation);
            $this->fail('Expected InvalidInvitationTransition.');
        } catch (InvalidInvitationTransition $invalidInvitationTransition) {
            $this->assertSame('invalid-invitation-transition', $invalidInvitationTransition->type());
        }
    }

    /**
     * @param class-string $eventClass
     */
    private function assertRecordedExactly(Invitation $invitation, string $eventClass): void
    {
        $events = $invitation->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf($eventClass, $events[0]);
    }

    private function accepted(): Invitation
    {
        $invitation = $this->sent();
        $invitation->accept();
        $invitation->pullDomainEvents();

        return $invitation;
    }

    private function create(): Invitation
    {
        return Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $this->mint()->token);
    }

    private function sent(): Invitation
    {
        $invitation = $this->create();
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return $invitation;
    }

    private function mint(): GeneratedToken
    {
        return SingleUseToken::mint($this->future());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function future(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-16T10:00:00+00:00');
    }

    private function past(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-10T10:00:00+00:00');
    }
}
