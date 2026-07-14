<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Invitation\Application\AcceptInvitation;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Exception\InvalidToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every ineligible-token case — malformed, non-existent, wrong secret, expired, non-SENT, or pointing at a
 * non-INVITED identity — must raise the SAME opaque {@see InvalidToken} without mutating state or emitting an
 * event; the happy path activates the identity,
 * retires the invitation and publishes exactly one {@see InvitationAccepted} — all in one transaction.
 *
 * The high object coupling is the cost of exercising the real retire-then-act graph (two repositories, the
 * identity aggregate, the invitation aggregate and its events) rather than mocking the flow away.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(AcceptInvitation::class)]
final class AcceptInvitationTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60';

    private const string USER_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b61';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b62';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    private int $kdfRuns = 0;

    #[Test]
    public function itActivatesTheIdentityRetiresTheInvitationAndPublishesOnce(): void
    {
        [$invitation, $token] = $this->sentInvitation($this->future());
        $user = UserMother::invited(self::USER_ID);
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();

        $accepted = $this->useCase($invitations, $users, $eventBus)->accept($token, $this->password());

        $this->assertSame(1, $this->kdfRuns);
        $this->assertSame(self::USER_ID, $accepted->userId);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $accepted->email);
        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $this->assertSame(InvitationStatus::ACCEPTED, $invitation->status());
        $this->assertSame([$user], $users->saved);
        $this->assertSame([$invitation], $invitations->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(InvitationAccepted::class, $eventBus->publishedEvents[0]);
    }

    #[Test]
    public function itRejectsAMalformedTokenWithoutMutation(): void
    {
        foreach (['no-separator', self::INVITATION_ID . '.', 'not-a-uuid.some-secret', '.secret'] as $malformed) {
            $invitation = $this->pristineSentInvitation();
            $invitations = new InMemoryInvitationRepository($invitation);
            $users = new InMemoryUserRepository(UserMother::invited(self::USER_ID));
            $eventBus = new RecordingEventBus();

            $this->assertRejected(
                fn (): mixed => $this->useCase($invitations, $users, $eventBus)->accept($malformed, $this->password()),
                $invitations,
                $users,
                $eventBus,
            );
        }
    }

    #[Test]
    public function itRejectsANonExistentInvitation(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $users = new InMemoryUserRepository(UserMother::invited(self::USER_ID));
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)
                ->accept(self::INVITATION_ID . '.any-secret', $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    #[Test]
    public function itRejectsAWrongSecret(): void
    {
        [$invitation] = $this->sentInvitation($this->future());
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(self::USER_ID));
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)
                ->accept(self::INVITATION_ID . '.the-wrong-secret', $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    #[Test]
    public function itRejectsAnExpiredToken(): void
    {
        [$invitation, $token] = $this->sentInvitation($this->past());
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(self::USER_ID));
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)->accept($token, $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    #[Test]
    public function itRejectsAnAlreadyAcceptedInvitationWithTheSameValidToken(): void
    {
        [$invitation, $token] = $this->sentInvitation($this->future());
        $invitation->accept();
        $invitation->pullDomainEvents();

        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository(UserMother::invited(self::USER_ID));
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)->accept($token, $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    #[Test]
    public function itRejectsWhenTheInvitedIdentityIsMissing(): void
    {
        [$invitation, $token] = $this->sentInvitation($this->future());
        $invitations = new InMemoryInvitationRepository($invitation);
        $users = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)->accept($token, $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    #[Test]
    public function itRejectsWhenTheInvitedIdentityIsNoLongerInvited(): void
    {
        [$invitation, $token] = $this->sentInvitation($this->future());
        $invitations = new InMemoryInvitationRepository($invitation);
        // A SENT invitation pointing at an already-ACTIVE identity is a desync; it must collapse to the opaque
        // wall, never leak the distinguishable 409 that activate() would raise on a non-INVITED identity.
        $users = new InMemoryUserRepository(UserMother::create(self::USER_ID));
        $eventBus = new RecordingEventBus();

        $this->assertRejected(
            fn (): mixed => $this->useCase($invitations, $users, $eventBus)->accept($token, $this->password()),
            $invitations,
            $users,
            $eventBus,
        );
    }

    /**
     * @param callable(): mixed $act
     */
    private function assertRejected(
        callable $act,
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        RecordingEventBus $eventBus,
    ): void {
        try {
            $act();
            $this->fail('Expected InvalidToken.');
        } catch (InvalidToken $invalidToken) {
            $this->assertSame('invalid-token', $invalidToken->type());
        }

        // A rejected accept must never have paid the deferred KDF: a garbage token costing an argon2id run
        // would hand an anonymous caller a CPU-amplification vector.
        $this->assertSame(0, $this->kdfRuns);
        $this->assertSame([], $invitations->saved);
        $this->assertSame([], $users->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    private function useCase(
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        RecordingEventBus $eventBus,
    ): AcceptInvitation {
        return new AcceptInvitation(
            $invitations,
            $users,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * @return array{0: Invitation, 1: string} the SENT invitation and its raw `<id>.<secret>` accept token
     */
    private function sentInvitation(DateTimeImmutable $expiresAt): array
    {
        $generated = SingleUseToken::mint($expiresAt);
        $invitation = Invitation::create(self::INVITATION_ID, self::ORG_ID, self::USER_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return [$invitation, self::INVITATION_ID . '.' . $generated->plaintext()];
    }

    private function pristineSentInvitation(): Invitation
    {
        [$invitation] = $this->sentInvitation($this->future());

        return $invitation;
    }

    private function future(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-16T10:00:00+00:00');
    }

    private function past(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-10T10:00:00+00:00');
    }

    /**
     * @return Closure(): HashedPassword
     */
    private function password(): Closure
    {
        return function (): HashedPassword {
            ++$this->kdfRuns;

            return HashedPassword::fromHash('a-precomputed-hash');
        };
    }
}
