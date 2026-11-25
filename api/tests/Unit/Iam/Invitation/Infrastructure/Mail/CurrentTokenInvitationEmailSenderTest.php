<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Mail;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Infrastructure\Mail\CurrentTokenInvitationEmailSender;
use Erpify\Iam\Invitation\Infrastructure\Mail\InvitationLinkSuperseded;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\SpyInvitationEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A link is mailed only while it is still the live one of a `SENT` invitation. The race it narrows — two resends whose
 * mails arrive out of order — is simulated the one way a single process can: the token is rotated AFTER the first
 * send was prepared and BEFORE it reaches the hand-off, which is exactly the interleaving the check exists for.
 *
 * @internal
 */
#[CoversClass(CurrentTokenInvitationEmailSender::class)]
final class CurrentTokenInvitationEmailSenderTest extends TestCase
{
    private const string INVITATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private const string RECIPIENT = 'invitee@erpify.test';

    private const string NOW = '2026-07-16T10:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        // The injected clock must agree with the ambient one the aggregates read, or it throws when read.
        SystemClock::set(FixedClock::at(self::NOW));
    }

    public function testALiveLinkIsMailed(): void
    {
        [$invitation, $secret] = $this->sentInvitation();
        $spy = new SpyInvitationEmailSender();

        $this->sender($spy, $invitation)->send(self::RECIPIENT, self::INVITATION_ID . '.' . $secret);

        $this->assertSame(
            [['recipient' => self::RECIPIENT, 'token' => self::INVITATION_ID . '.' . $secret]],
            $spy->sent,
        );
    }

    public function testALinkThatALaterResendReplacedIsNotMailed(): void
    {
        [$invitation, $firstSecret] = $this->sentInvitation();
        $spy = new SpyInvitationEmailSender();
        $sender = $this->sender($spy, $invitation);

        // A second resend commits its token while the first mail is still on its way to the hand-off.
        $second = SingleUseToken::mint(new DateTimeImmutable('2026-07-23T10:00:00+00:00'));
        $invitation->resend($second->token);

        try {
            $sender->send(self::RECIPIENT, self::INVITATION_ID . '.' . $firstSecret);
            $this->fail('A superseded link must not be mailed.');
        } catch (InvitationLinkSuperseded) {
            $this->assertSame([], $spy->sent, 'nothing reached the mailer');
        }

        $replacement = new SpyInvitationEmailSender();
        $this->sender($replacement, $invitation)->send(self::RECIPIENT, self::INVITATION_ID . '.' . $second->plaintext());
        $this->assertCount(1, $replacement->sent, 'the replacement is still mailed');
    }

    public function testAnExpiredLinkIsNotMailed(): void
    {
        [$invitation, $secret] = $this->sentInvitation(expiresAt: '2026-07-16T09:59:59+00:00');
        $spy = new SpyInvitationEmailSender();

        $this->expectException(InvitationLinkSuperseded::class);

        try {
            $this->sender($spy, $invitation)->send(self::RECIPIENT, self::INVITATION_ID . '.' . $secret);
        } finally {
            $this->assertSame([], $spy->sent);
        }
    }

    public function testAnInvitationThatIsNoLongerSentIsNotMailed(): void
    {
        [$invitation, $secret] = $this->sentInvitation();
        $invitation->revoke();
        $spy = new SpyInvitationEmailSender();

        $this->expectException(InvitationLinkSuperseded::class);

        try {
            $this->sender($spy, $invitation)->send(self::RECIPIENT, self::INVITATION_ID . '.' . $secret);
        } finally {
            $this->assertSame([], $spy->sent);
        }
    }

    public function testAnInvitationThatDoesNotExistIsNotMailed(): void
    {
        $spy = new SpyInvitationEmailSender();
        $sender = new CurrentTokenInvitationEmailSender(
            $spy,
            new InMemoryInvitationRepository(),
            new FixedClock(SystemClock::now()),
        );

        $this->expectException(InvitationLinkSuperseded::class);

        $sender->send(self::RECIPIENT, self::INVITATION_ID . '.some-secret');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedTokens(): iterable
    {
        yield 'no separator' => ['not-a-token'];
        yield 'an empty secret' => [self::INVITATION_ID . '.'];
        yield 'a selector that is not an id' => ['selector.some-secret'];
        yield 'nothing at all' => [''];
    }

    #[DataProvider('provideMalformedTokens')]
    public function testAMalformedTokenIsNotMailed(string $token): void
    {
        [$invitation] = $this->sentInvitation();
        $spy = new SpyInvitationEmailSender();

        $this->expectException(InvitationLinkSuperseded::class);

        try {
            $this->sender($spy, $invitation)->send(self::RECIPIENT, $token);
        } finally {
            $this->assertSame([], $spy->sent);
        }
    }

    public function testTheRefusalNamesNeitherTheAddressNorTheToken(): void
    {
        $exception = new InvitationLinkSuperseded();

        $this->assertStringNotContainsString(self::RECIPIENT, $exception->getMessage());
        $this->assertStringNotContainsString(self::INVITATION_ID, $exception->getMessage());
    }

    private function sender(SpyInvitationEmailSender $spy, Invitation $invitation): CurrentTokenInvitationEmailSender
    {
        return new CurrentTokenInvitationEmailSender(
            $spy,
            new InMemoryInvitationRepository($invitation),
            new FixedClock(SystemClock::now()),
        );
    }

    /**
     * @return array{0: Invitation, 1: string} the SENT invitation and its secret
     */
    private function sentInvitation(string $expiresAt = '2026-07-23T10:00:00+00:00'): array
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable($expiresAt));
        $invitation = Invitation::create(self::INVITATION_ID, self::ORGANIZATION_ID, self::USER_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return [$invitation, $generated->plaintext()];
    }
}
