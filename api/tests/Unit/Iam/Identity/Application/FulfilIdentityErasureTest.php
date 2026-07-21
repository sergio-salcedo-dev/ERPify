<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\SelfErasureForbidden;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The GDPR erasure orchestrator chains, as one unit, the "keep ≥1 active ADMIN" guard, the identity erasure,
 * the audit-trail anonymisation, the session hard-delete and the combined compliance self-audit. These tests
 * drive the collaborators through their in-memory specs and observe the chaining, the order (the self-audit
 * is written only after every mutation succeeds) and the two pre-transaction guards. DB-level atomicity — a
 * failed link leaving *nothing* persisted — is a store concern proven by the functional and Behat layers; the
 * unit level pins the control flow that makes that atomicity meaningful.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class FulfilIdentityErasureTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e31';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    public function testChainsIdentityErasureTrailAnonymisationAndSessionPurgeThenSelfAuditsTheCombinedOp(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID));
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository($this->sessionFor(UserMother::DEFAULT_ID));
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::ACTING_ADMIN_ID => true,
        ]);

        $result = $this->useCase($users, $tokens, $audit, $anonymiser, $sessions, $directory)
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertSame(3, $result->anonymizedAuditRows);
        $this->assertSame(1, $result->sessionsDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $anonymiser->anonymisedActorIds);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->deleteAllCalls);

        // Two security rows in order: the identity erasure's own, then the orchestrator's combined record.
        $this->assertCount(2, $audit->records);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
        $this->assertSame('GDPR_ERASURE_EXECUTED', $audit->records[1]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[1]['level']);
        $this->assertSame([
            'subject_user_id' => UserMother::DEFAULT_ID,
            'anonymized_actor_id' => $anonymiser->pseudonym,
            'affected_rows' => 3,
            'reset_tokens_deleted' => 1,
            'sessions_deleted' => 1,
        ], $audit->records[1]['metadata']);
    }

    public function testTheLastActiveAdministratorCannotBeErasedAndNothingIsTouched(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->useCase(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                $audit,
                $anonymiser,
                $sessions,
                $directory,
            )->execute(UserMother::DEFAULT_ID);
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // rejected inside the transaction, before any erase / anonymise / purge / self-audit
        }

        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $anonymiser->anonymisedActorIds);
        $this->assertSame([], $sessions->deleteAllCalls);
        $this->assertSame([], $audit->records);
    }

    public function testAnActorCannotEraseTheirOwnIdentity(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $useCase = $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new RecordingAuditLogger(),
            $anonymiser,
            $sessions,
            $directory,
            ActorContext::forUser(UserMother::DEFAULT_ID),
        );

        try {
            $useCase->execute(UserMother::DEFAULT_ID);
            $this->fail('Expected SelfErasureForbidden.');
        } catch (SelfErasureForbidden) {
            // refused before the transaction opens — the admin guard is never even consulted
        }

        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $directory->askedWithout);
        $this->assertSame([], $anonymiser->anonymisedActorIds);
        $this->assertSame([], $sessions->deleteAllCalls);
    }

    public function testAnUnknownSubjectErasesNothingSelfAuditsNothingAndReportsIdentityNotErased(): void
    {
        $users = new InMemoryUserRepository();
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 0);
        $sessions = new InMemorySessionRepository();
        // A different administrator survives the exclusion, so the guard passes and the absent id reaches the
        // idempotent erase — the surface (controller) decides the 404, not this service.
        $directory = new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]);

        $result = $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            $audit,
            $anonymiser,
            $sessions,
            $directory,
        )->execute(UserMother::DEFAULT_ID);

        $this->assertFalse($result->identityErased);
        $this->assertFalse($result->erasedAnything());
        $this->assertSame([], $audit->records);
    }

    public function testAMalformedSubjectIdIsRejectedBeforeAnyWork(): void
    {
        $users = new InMemoryUserRepository();
        $useCase = $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new RecordingAuditLogger(),
            new RecordingAuditActorAnonymiser(matchCount: 0),
            new InMemorySessionRepository(),
            new InMemoryActiveAdministratorDirectory([]),
        );

        $this->expectException(InvalidUuidException::class);

        $useCase->execute('not-a-uuid');
    }

    public function testAFailedSessionPurgeAbortsBeforeTheComplianceSelfAudit(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        $sessions->failOnDelete = true;

        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::ACTING_ADMIN_ID => true,
        ]);

        try {
            $this->useCase(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                $audit,
                $anonymiser,
                $sessions,
                $directory,
            )->execute(UserMother::DEFAULT_ID);
            $this->fail('Expected the session purge failure to propagate.');
        } catch (RuntimeException) {
            // the failure aborts the unit of work — in production the transaction rolls everything back
        }

        // The combined self-audit is gated behind a successful purge: only the inner erasure row was written,
        // never GDPR_ERASURE_EXECUTED.
        $this->assertCount(1, $audit->records);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingAuditLogger $audit,
        RecordingAuditActorAnonymiser $anonymiser,
        InMemorySessionRepository $sessions,
        InMemoryActiveAdministratorDirectory $directory,
        ?ActorContext $actor = null,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject($users, $tokens, $audit, new InlineTransactionManager()),
            $anonymiser,
            $directory,
            new PurgeUserSessions($sessions),
            $audit,
            new FixedActorContextFactory($actor ?? ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }

    private function tokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-21T13:00:00+00:00'));

        return PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token);
    }

    private function sessionFor(string $userId): Session
    {
        $session = Session::start(
            SessionId::generate()->toString(),
            $userId,
            self::ORGANIZATION_ID,
            'Test client',
            '127.0.0.1',
            new DateTimeImmutable('2026-07-22T13:00:00+00:00'),
        );
        $session->pullDomainEvents();

        return $session;
    }
}
