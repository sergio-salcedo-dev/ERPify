<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\PasswordResetTokenMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\RecoverySecretMother;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two credential-recovery artefacts the chain destroys alongside the identity — the pending reset token
 * and the recovery secret — and the two compliance entries that report how many of each went.
 *
 * Sibling of {@see FulfilIdentityErasureTest}, which covers the chain's own result and its guards. What is
 * kept apart here is a claim about CONTENT rather than about sequence, and it needs a population neither of
 * the other cases has: both artefacts present at once, so a count that is zero on both sides of the chain
 * cannot pass for one that is threaded through.
 *
 * **The recovery secret is why this test exists rather than one more assertion elsewhere.** It is the only
 * artefact in the chain with a ten-year TTL and no retention sweep behind it, so an erasure that missed it
 * would leave a person's identifier in place for a decade with nothing scheduled to notice — and every other
 * test of this chain seeds none, which means every one of them would stay green if the delete were removed.
 *
 * The metadata is asserted as a WHOLE LITERAL on both entries, not key by key: a compliance record that
 * silently gains or loses a count is exactly the drift these entries exist to make visible, and a
 * key-by-key assertion cannot see either direction.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
#[CoversClass(EraseIdentitySubject::class)]
final class FulfilIdentityErasureCredentialArtefactTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    #[Test]
    public function bothCredentialArtefactsAreDestroyedAndCountedInBothComplianceEntries(): void
    {
        $audit = new RecordingAuditLogger();
        $secrets = new InMemoryRecoverySecretRepository(RecoverySecretMother::mintedFor());
        $tokens = new InMemoryPasswordResetTokenRepository(PasswordResetTokenMother::pendingFor());

        $result = $this->useCase($audit, $tokens, $secrets)->execute(UserMother::DEFAULT_ID);

        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertSame(1, $result->recoverySecretsDeleted);
        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertSame([UserMother::DEFAULT_ID], $secrets->deleteAllForUserCalls);

        $this->assertCount(2, $audit->records);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
        $this->assertSame(
            ['reset_tokens_deleted' => 1, 'recovery_secrets_deleted' => 1],
            $audit->records[0]['metadata'],
        );

        $this->assertSame('GDPR_ERASURE_EXECUTED', $audit->records[1]['action']);
        $this->assertSame([
            'affected_rows' => 0,
            'anonymized_actor_id' => RecordingAuditActorAnonymiser::PSEUDONYM,
            'anonymized_resource_rows' => 0,
            'anonymized_event_rows' => 0,
            'recovery_secrets_deleted' => 1,
            'reset_tokens_deleted' => 1,
            'sessions_deleted' => 0,
            'memberships_deleted' => 0,
            'invitations_deleted' => 0,
        ], $audit->records[1]['metadata']);
    }

    #[Test]
    public function aSubjectWhoseOnlyResidueIsARecoverySecretStillCountsAsAnErasure(): void
    {
        // The identity row is already gone — a re-run, or a subject deleted by some earlier path — so every
        // other disjunct of `erasedAnything()` is false. Without the recovery-secret disjunct the chain would
        // report "nothing to erase" and write NO compliance entry, while a person's identifier was in fact
        // removed. That is the one case where the new count decides the outcome rather than describing it.
        $audit = new RecordingAuditLogger();
        $secrets = new InMemoryRecoverySecretRepository(RecoverySecretMother::mintedFor());

        $result = $this->useCase($audit, new InMemoryPasswordResetTokenRepository(), $secrets)
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertFalse($result->identityErased);
        $this->assertSame(1, $result->recoverySecretsDeleted);
        $this->assertTrue($result->erasedAnything());
        $this->assertCount(2, $audit->records);
    }

    private function useCase(
        RecordingAuditLogger $audit,
        InMemoryPasswordResetTokenRepository $tokens,
        InMemoryRecoverySecretRepository $secrets,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                new InMemoryUserRepository(),
                $tokens,
                $secrets,
                new InlineTransactionManager(),
            ),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            new RecordingEventStoreSubjectAnonymiser(),
            // The subject is not an administrator — the only shape erasure accepts.
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership(new InMemoryMembershipRepository()),
            new PurgeUserInvitations(new InMemoryInvitationRepository()),
            $audit,
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }
}
