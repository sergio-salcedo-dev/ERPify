<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordInvitationAudit;
use Erpify\Iam\Invitation\Application\ResendInvitation;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who resent a person's invitation: one `security` row per act, naming the invitee, written while the resend's
 * own transaction is still open. What the row says and when it is withheld is {@see RecordInvitationAudit}'s
 * test; this one pins that the use case reaches it, once, inside its boundary.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResendInvitation::class)]
final class ResendInvitationAuditTest extends TestCase
{
    private const string INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b90';

    private const string SECOND_INVITATION_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b93';

    private const string ORG_ID = '0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b92';

    private const string NOW = '2026-07-13T10:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    #[Test]
    public function itAuditsTheResendByIdAsASecurityRowNamingTheInviteeWhileTheLocksAreHeld(): void
    {
        [$invitation] = $this->sentInvitation();
        $transactions = new CountingTransactionManager();
        $auditLogger = new TransactionObservingAuditLogger($transactions);

        $this->resend(
            new InMemoryInvitationRepository($invitation),
            new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID)),
            new SpyInvitationEmailSender(),
            new RecordingEventBus(),
            $transactions,
            $auditLogger,
        )->resend(self::INVITATION_ID);

        // The resource is the PERSON, never the invitation: it is the id the erasure's pass over the trail
        // matches, and the row is inside the transaction because the invitation locks are what hold that
        // erasure back until it commits.
        $this->assertCount(1, $auditLogger->records);
        $this->assertSame('INVITATION_RESENT', $auditLogger->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $auditLogger->records[0]['level']);
        $this->assertSame('User', $auditLogger->records[0]['resource']?->type);
        $this->assertSame(UserMother::DEFAULT_ID, $auditLogger->records[0]['resource']->id);
        $this->assertSame([], $auditLogger->records[0]['metadata']);
        $this->assertTrue($auditLogger->records[0]['inTransaction']);
    }

    #[Test]
    public function itAuditsOneRowPerResendHoweverManyInvitationsItReissues(): void
    {
        [$first] = $this->sentInvitation();
        [$second] = $this->sentInvitation(self::SECOND_INVITATION_ID);
        $transactions = new CountingTransactionManager();
        $auditLogger = new TransactionObservingAuditLogger($transactions);

        $this->resend(
            new InMemoryInvitationRepository($first, $second),
            new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID)),
            new SpyInvitationEmailSender(),
            new RecordingEventBus(),
            $transactions,
            $auditLogger,
        )->resendForInvitedUser(UserMother::DEFAULT_ID);

        $this->assertCount(1, $auditLogger->records);
        $this->assertSame('INVITATION_RESENT', $auditLogger->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $auditLogger->records[0]['level']);
        $this->assertSame(UserMother::DEFAULT_ID, $auditLogger->records[0]['resource']?->id);
        $this->assertTrue($auditLogger->records[0]['inTransaction']);
    }

    private function resend(
        InMemoryInvitationRepository $invitations,
        InMemoryUserRepository $users,
        SpyInvitationEmailSender $emailSender,
        RecordingEventBus $eventBus,
        CountingTransactionManager $transactions,
        TransactionObservingAuditLogger $auditLogger,
    ): ResendInvitation {
        return new ResendInvitation(
            $invitations,
            $users,
            new SendInvitationEmailBestEffort($emailSender, new NullLogger()),
            $eventBus,
            $transactions,
            new FixedClock(new DateTimeImmutable(self::NOW)),
            new RecordInvitationAudit($users, $auditLogger, new InlineTransactionManager()),
        );
    }

    /**
     * @return array{0: Invitation}
     */
    private function sentInvitation(string $id = self::INVITATION_ID): array
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-16T10:00:00+00:00'));
        $invitation = Invitation::create($id, self::ORG_ID, UserMother::DEFAULT_ID, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();

        return [$invitation];
    }
}
