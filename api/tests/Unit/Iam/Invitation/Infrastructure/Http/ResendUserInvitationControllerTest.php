<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordInvitationAudit;
use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Exception\InvitationEmailNotSent;
use Erpify\Iam\Invitation\Infrastructure\Http\ResendUserInvitationController;
use Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Invitation\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Cli\CapturingInvitationEmailSender;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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
#[CoversClass(InvitationEmailNotSent::class)]
final class ResendUserInvitationControllerTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5ba0';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5ba1';

    private const string NOW = '2026-07-17T10:00:00+00:00';

    private RecordingAuditLogger $auditLogger;

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
        $this->auditLogger = new RecordingAuditLogger();
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

    #[Test]
    public function aSpentBudgetRefusesBeforeTheLinkIsTouched(): void
    {
        $emailSender = CapturingInvitationEmailSender::accepting();
        $controller = $this->controller($emailSender, budget: 1);
        $controller(UserMother::DEFAULT_ID);
        $deliveredToken = $emailSender->capturedToken();

        try {
            $controller(UserMother::DEFAULT_ID);
            $this->fail('A spent per-invitee budget must refuse out loud.');
        } catch (RateLimitExceeded) {
            // Asserted below: the budget protects the link the invitee holds, so a refusal reaches no row.
        }

        // The link mailed by the admitted call is still the last one sent, and only that call left a row.
        $this->assertSame($deliveredToken, $emailSender->capturedToken());
        $this->assertCount(1, $this->auditLogger->records);
    }

    #[Test]
    public function aMalformedIdIsRefusedWithoutSpendingTheInviteesBudget(): void
    {
        $controller = $this->controller(CapturingInvitationEmailSender::accepting(), budget: 1);

        try {
            $controller(' ' . UserMother::DEFAULT_ID);
            $this->fail('A padded id must be refused as a malformed UUID.');
        } catch (InvalidUuidException) {
            // Asserted below: the refusal must leave the real person's single slot untouched.
        }

        $response = $controller(UserMother::DEFAULT_ID);
        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }

    private function controller(InvitationEmailSender $emailSender, int $budget = 5): ResendUserInvitationController
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

        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));

        return new ResendUserInvitationController(new ResendInvitation(
            new InMemoryInvitationRepository($invitation),
            $users,
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
            new RecordInvitationAudit($users, $this->auditLogger, new InlineTransactionManager()),
        ), new InvitationResendThrottle(
            new RateLimiterFactory(
                ['id' => 'per_invitee', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '1 day'],
                new InMemoryStorage(),
            ),
            new RequestStack(),
            'test-kernel-secret',
        ));
    }
}
