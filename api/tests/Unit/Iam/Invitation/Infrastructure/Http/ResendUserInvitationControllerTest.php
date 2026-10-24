<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitationEmailNotSent;
use Erpify\Iam\Invitation\Infrastructure\Http\ResendUserInvitationController;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli\CapturingInvitationEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the resend controller over the real {@see ResendInvitation} on in-memory repositories, because the
 * two properties worth pinning live on the wire: the accept token — a credential — never reaches the response,
 * and a send the mailer refused is reported instead of answered with a success the invitee cannot use. The
 * second path is unreachable from the acceptance lane, whose mailer never fails.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResendUserInvitationController::class)]
final class ResendUserInvitationControllerTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5ba0';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5ba1';

    private const string NOW = '2026-07-17T10:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    #[Test]
    public function itResendsAndAnswersABodylessNoContentThatCarriesNoToken(): void
    {
        $emailSender = CapturingInvitationEmailSender::accepting();

        $response = $this->controller($emailSender)(UserMother::DEFAULT_ID);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', (string) $response->getContent());
        // The send happened, and with a token the response could have echoed — which is what makes the empty
        // body an assertion rather than an accident of a flow that minted nothing.
        $this->assertStringStartsWith(self::INVITATION_ID . '.', $emailSender->capturedToken());
    }

    #[Test]
    public function itReportsARefusedSendAsServiceUnavailableWithoutTheToken(): void
    {
        $emailSender = CapturingInvitationEmailSender::refusing();

        try {
            $this->controller($emailSender)(UserMother::DEFAULT_ID);
            $this->fail('Expected InvitationEmailNotSent.');
        } catch (InvitationEmailNotSent $invitationEmailNotSent) {
            $this->assertSame('invitation-email-not-sent', $invitationEmailNotSent->type());
            // The CLI hands the token over when the mail fails; the wire never may, and the problem goes to the
            // error tracker, so it carries neither the token nor the person it was minted for.
            $this->assertSame([], $invitationEmailNotSent->context());
            $this->assertStringNotContainsString($emailSender->capturedSecret(), $invitationEmailNotSent->title());
        }
    }

    private function controller(InvitationEmailSender $emailSender): ResendUserInvitationController
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-18T10:00:00+00:00'));
        $invitation = Invitation::create(
            self::INVITATION_ID,
            self::ORG_ID,
            UserMother::DEFAULT_ID,
            $generated->token,
        );
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return new ResendUserInvitationController(new ResendInvitation(
            new InMemoryInvitationRepository($invitation),
            new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID)),
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        ));
    }
}
