<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\FulfilIdentityErasureResult;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Exception\AdministratorErasureRequiresDemotion;
use Erpify\Iam\Identity\Domain\Exception\SelfErasureForbidden;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditResourceAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditSubjectRowLock;
use Erpify\Tests\Unit\Shared\Event\Infrastructure\Double\RecordingEventStoreSubjectAnonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The GDPR erasure orchestrator chains, as one unit, the administrator refusal, the identity erasure,
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
#[CoversClass(FulfilIdentityErasureResult::class)]
#[CoversClass(SelfErasureForbidden::class)]
#[CoversClass(AdministratorErasureRequiresDemotion::class)]
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
        // The subject is not an administrator — the only shape erasure accepts.
        $directory = new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]);
        // Deliberately different from the actor count: the two axes are different row sets, so a summing
        // implementation would be invisible if both doubles reported the same number.
        $resourceAnonymiser = new RecordingAuditResourceAnonymiser(matchCount: 2);

        $result = $this->useCase(
            $users,
            $tokens,
            $audit,
            $anonymiser,
            $sessions,
            $directory,
            resourceAnonymiser: $resourceAnonymiser,
        )->execute(UserMother::DEFAULT_ID);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertSame(3, $result->anonymizedAuditRows);
        $this->assertSame(2, $result->anonymizedResourceRows);
        $this->assertSame(1, $result->sessionsDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $anonymiser->anonymisedActorIds);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->deleteAllCalls);

        // The resource axis is erased under this context's own type, with the ACTOR pass's pseudonym —
        // minting a second one would split one person into two anonymous identities.
        $this->assertSame([[
            'type' => 'User',
            'id' => UserMother::DEFAULT_ID,
            'pseudonym' => $anonymiser->pseudonym,
        ]], $resourceAnonymiser->calls);

        // Two security rows in order: the subject's erasure record, then the orchestrator's combined one.
        $this->assertCount(2, $audit->records);
        $this->assertCount(1, $resourceAnonymiser->resources);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
        // The subject's real id is written and cleared from ONE value, so the two cannot name different
        // resources. Asserted by identity, not equality: two objects that merely compare equal would let a
        // future refactor rebuild them apart, and the drift this guards against is exactly a compliance row
        // whose identifier the anonymise call never covers.
        $this->assertSame($audit->records[0]['resource'], $resourceAnonymiser->resources[0]);
        // Counts only beside it — nothing identifying the subject travels in the JSON no anonymiser reaches.
        $this->assertSame(['reset_tokens_deleted' => 1], $audit->records[0]['metadata']);

        $this->assertSame('GDPR_ERASURE_EXECUTED', $audit->records[1]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[1]['level']);
        // Counts and the pseudonym — never the subject id, which is what would re-link the anonymised trail.
        // The pseudonym is this entry's contract (D4.1): it is what lets a row marked `actor_erased` be
        // matched back to the compliance entry that erased it, and the same value the resource axis carries.
        $this->assertSame([
            'affected_rows' => 3,
            'anonymized_actor_id' => $anonymiser->pseudonym,
            'anonymized_resource_rows' => 2,
            'anonymized_event_rows' => 0,
            'reset_tokens_deleted' => 1,
            'sessions_deleted' => 1,
            'memberships_deleted' => 0,
            'invitations_deleted' => 0,
        ], $audit->records[1]['metadata']);
    }

    public function testNeitherComplianceRowCarriesTheSubjectIdInItsMetadata(): void
    {
        // Both rows at once, and over the serialised JSON rather than over key names: a future key holding
        // the id under any name is the same defect. Case-insensitively, because the id arrives spelled as the
        // caller spelled it — `Uuid::ensure()` validates without normalising — and `metadata` is jsonb TEXT,
        // the one column Postgres does not case-fold for us.
        $audit = new RecordingAuditLogger();

        $this->useCase(
            new InMemoryUserRepository(UserMother::create()),
            new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID)),
            $audit,
            new RecordingAuditActorAnonymiser(matchCount: 3),
            new InMemorySessionRepository($this->sessionFor(UserMother::DEFAULT_ID)),
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
        )->execute(UserMother::DEFAULT_ID);

        $this->assertCount(2, $audit->records);

        foreach ($audit->records as $record) {
            $this->assertStringNotContainsStringIgnoringCase(
                UserMother::DEFAULT_ID,
                \json_encode($record['metadata'], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testResourceRowsAloneStillProduceComplianceEvidence(): void
    {
        // Nothing left of the identity — no live user, no tokens, no authored trail rows, no sessions — but
        // rows still NAME the subject, e.g. an administrator who changed their roles. That is a real
        // mutation, so it must not commit without its GDPR_ERASURE_EXECUTED evidence.
        $users = new InMemoryUserRepository();
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 0);
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]);

        $result = $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            $audit,
            $anonymiser,
            $sessions,
            $directory,
            resourceAnonymiser: new RecordingAuditResourceAnonymiser(matchCount: 4),
        )->execute(UserMother::DEFAULT_ID);

        $this->assertFalse($result->identityErased);
        $this->assertSame(4, $result->anonymizedResourceRows);
        $this->assertTrue($result->erasedAnything());
        $this->assertSame(['GDPR_ERASURE_EXECUTED'], \array_column($audit->records, 'action'));
    }

    public function testAnAdministratorCannotBeErasedUntilDemotedAndNothingIsTouched(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $audit = new RecordingAuditLogger();
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        // The two links that cross a bounded context. They are asserted here for the same reason as the
        // sessions: "nothing is touched" is a claim about EVERY link of the chain, and a list that names only
        // some of them reads as exhaustive while leaving the rest free to run.
        $memberships = new InMemoryMembershipRepository();
        $invitations = new InMemoryInvitationRepository();
        // A peer administrator remains, so the "keep ≥1 active ADMIN" invariant is not what refuses this.
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
                memberships: $memberships,
                invitations: $invitations,
            )->execute(UserMother::DEFAULT_ID);
            $this->fail('Expected AdministratorErasureRequiresDemotion.');
        } catch (AdministratorErasureRequiresDemotion) {
            // rejected inside the transaction, before any erase / anonymise / purge / self-audit
        }

        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $anonymiser->anonymisedActorIds);
        $this->assertSame([], $sessions->deleteAllCalls);
        $this->assertSame([], $memberships->deleteAllForUserCalls);
        $this->assertSame([], $invitations->deleteAllForInvitedUserCalls);
        $this->assertSame([], $audit->records);
    }

    public function testASuspendedAdministratorIsRefusedToo(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        // Valued `false`: the role is still carried, the backing user is simply no longer ACTIVE. The refusal
        // is about the role, so a suspended administrator is not a way around the demotion.
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => false,
            self::ACTING_ADMIN_ID => true,
        ]);

        $this->expectException(AdministratorErasureRequiresDemotion::class);

        $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new RecordingAuditLogger(),
            $anonymiser,
            $sessions,
            $directory,
        )->execute(UserMother::DEFAULT_ID);
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
        $this->assertSame([], $directory->askedWhetherAdministrator);
        $this->assertSame([], $anonymiser->anonymisedActorIds);
        $this->assertSame([], $sessions->deleteAllCalls);
    }

    public function testAnActorCannotEraseTheirOwnIdentityUnderADifferentUuidCasing(): void
    {
        // DEFAULT_ID carries hex letters, so upper-casing it yields the same UUID in a different case.
        $upperCasedOwnId = \strtoupper(UserMother::DEFAULT_ID);

        $users = new InMemoryUserRepository(UserMother::create());
        $anonymiser = new RecordingAuditActorAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        // The sealed actor id is canonical lower case; the same UUID spelled upper case still passes Uuid::ensure
        // (RFC 4122 validation is case-insensitive). The self-erasure refusal must hold regardless of casing.
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
            $useCase->execute($upperCasedOwnId);
            $this->fail('Expected SelfErasureForbidden.');
        } catch (SelfErasureForbidden) {
            // refused before the transaction opens — a case-flipped id is still the actor's own identity
        }

        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $directory->askedWhetherAdministrator);
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

        $directory = new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]);

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
        ?RecordingAuditResourceAnonymiser $resourceAnonymiser = null,
        ?InMemoryMembershipRepository $memberships = null,
        ?InMemoryInvitationRepository $invitations = null,
        ?RecordingEventStoreSubjectAnonymiser $eventAnonymiser = null,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject($users, $tokens, new InlineTransactionManager()),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                $anonymiser,
                $resourceAnonymiser ?? new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            $eventAnonymiser ?? new RecordingEventStoreSubjectAnonymiser(),
            $directory,
            new PurgeUserSessions($sessions),
            new PurgeUserMembership($memberships ?? new InMemoryMembershipRepository()),
            new PurgeUserInvitations($invitations ?? new InMemoryInvitationRepository()),
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
            // Deliberately lapsed: erasure must reach the subject's expired rows, not only the admissible ones.
            new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        );
        $session->pullDomainEvents();

        return $session;
    }
}
