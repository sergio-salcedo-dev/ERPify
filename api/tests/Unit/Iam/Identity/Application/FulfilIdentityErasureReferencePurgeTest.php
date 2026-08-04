<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Invitation\Application\InMemoryInvitationRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Organization\Membership\Application\InMemoryMembershipRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditResourceAnonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two links of the erasure chain that reach outside the person's own context: the membership that
 * admitted the subject and every invitation addressed to them. Both columns are referenced by id across a
 * bounded context, and nothing in the schema references `identity_user`, so deleting it cascades nowhere —
 * tests pin that the chain removes them itself, scoped to the subject, and that reaching only them is still
 * a real mutation with compliance evidence of its own.
 *
 * Sibling of {@see FulfilIdentityErasureTest}, which covers the rest of the chain and its guards.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(FulfilIdentityErasure::class)]
final class FulfilIdentityErasureReferencePurgeTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    public function testErasesTheMembershipAndEveryInvitationOfTheSubjectAndSparesOtherPeoples(): void
    {
        $other = Uuid::generate();
        $memberships = new InMemoryMembershipRepository();
        $memberships->save($this->membershipFor(UserMother::DEFAULT_ID));
        $memberships->save($this->membershipFor($other));
        // A terminal invitation counts too: its lifecycle state has no bearing on the column being an id.
        $accepted = $this->invitationFor(UserMother::DEFAULT_ID);
        $accepted->markSent();
        $accepted->accept();

        $invitations = new InMemoryInvitationRepository(
            $this->invitationFor(UserMother::DEFAULT_ID),
            $accepted,
            $this->invitationFor($other),
        );
        $audit = new RecordingAuditLogger();

        $result = $this->useCase($audit, new InMemoryUserRepository(UserMother::create()), $memberships, $invitations)
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertSame(1, $result->membershipsDeleted);
        $this->assertSame(2, $result->invitationsDeleted);
        // Scoped to the subject: another person's link and invitation survive their neighbour's erasure.
        $this->assertNotInstanceOf(Membership::class, $memberships->findByUserId(UserMother::DEFAULT_ID));
        $this->assertInstanceOf(Membership::class, $memberships->findByUserId($other));
        $this->assertSame(1, $invitations->deleteAllForInvitedUser($other));

        // Two rows in order: the identity erasure's own, then the orchestrator's combined record. Counts
        // only — an id here would sit beside the anonymisation pseudonym and re-link the trail.
        $this->assertCount(2, $audit->records);
        $this->assertSame([
            'affected_rows' => 0,
            'anonymized_resource_rows' => 0,
            'reset_tokens_deleted' => 0,
            'sessions_deleted' => 0,
            'memberships_deleted' => 1,
            'invitations_deleted' => 2,
        ], $audit->records[1]['metadata']);
    }

    public function testTheSubjectIsMatchedWhateverTheCasingOfTheIdInHand(): void
    {
        // RFC 4122 hex is case-insensitive and the columns are Postgres `uuid`, so one id spelled two ways
        // is one id. An erasure that missed on casing would answer 204 and leave the reference behind.
        $memberships = new InMemoryMembershipRepository();
        $memberships->save($this->membershipFor(UserMother::DEFAULT_ID));

        $result = $this->useCase(new RecordingAuditLogger(), new InMemoryUserRepository(), $memberships)
            ->execute(\strtoupper(UserMother::DEFAULT_ID))
        ;

        $this->assertSame(1, $result->membershipsDeleted);
    }

    public function testReferenceRowsAloneStillProduceComplianceEvidence(): void
    {
        // No live identity and nothing else left, but the membership is still there — the exact residue this
        // pair of links exists for. Removing it is a real mutation, so it must not commit without evidence.
        $memberships = new InMemoryMembershipRepository();
        $memberships->save($this->membershipFor(UserMother::DEFAULT_ID));

        $audit = new RecordingAuditLogger();

        $result = $this->useCase($audit, new InMemoryUserRepository(), $memberships)
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertFalse($result->identityErased);
        $this->assertTrue($result->erasedAnything());
        $this->assertCount(1, $audit->records);
        $this->assertSame('GDPR_ERASURE_EXECUTED', $audit->records[0]['action']);
    }

    private function useCase(
        RecordingAuditLogger $audit,
        InMemoryUserRepository $users,
        InMemoryMembershipRepository $memberships,
        ?InMemoryInvitationRepository $invitations = null,
    ): FulfilIdentityErasure {
        return new FulfilIdentityErasure(
            new EraseIdentitySubject(
                $users,
                new InMemoryPasswordResetTokenRepository(),
                new InlineTransactionManager(),
            ),
            new RecordingAuditActorAnonymiser(matchCount: 0),
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            // The subject is not an administrator — the only shape erasure accepts.
            new InMemoryActiveAdministratorDirectory([self::ACTING_ADMIN_ID => true]),
            new PurgeUserSessions(new InMemorySessionRepository()),
            new PurgeUserMembership($memberships),
            new PurgeUserInvitations($invitations ?? new InMemoryInvitationRepository()),
            $audit,
            new FixedActorContextFactory(ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }

    private function membershipFor(string $userId): Membership
    {
        $membership = Membership::grant(Uuid::generate(), $userId, self::ORGANIZATION_ID, Role::VIEWER);
        $membership->pullDomainEvents();

        return $membership;
    }

    private function invitationFor(string $userId): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-21T13:00:00+00:00'));
        $invitation = Invitation::create(Uuid::generate(), self::ORGANIZATION_ID, $userId, $generated->token);
        $invitation->pullDomainEvents();

        return $invitation;
    }
}
