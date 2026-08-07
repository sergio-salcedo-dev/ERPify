<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeUserRoles;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The role-assignment use case diverges from its status-change sibling in two ways worth pinning: it asks the
 * {@see \Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory} only when the change would demote
 * an active administrator, and it treats a redundant set as a no-op instead of a conflict. Both are observed
 * here through hand-written doubles — the directory records whether it was consulted at all, and the session
 * teardown is observed through a real {@see RevokeSessionsBestEffort} over an in-memory session store.
 *
 * The compliance row the change writes is pinned on four axes rather than one, because it is the evidence the
 * erasure refusal's premise rests on: that it is emitted with both role sets, that its metadata shape is
 * frozen key-for-key, and that neither a no-op nor a refused demotion fabricates one. Each is a distinct way
 * the row could regress, so the method count reflects the surface under test, not a class doing several jobs.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(ChangeUserRoles::class)]
final class ChangeUserRolesTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    public function testReplacingTheSetSavesPublishesAndRevokesSessions(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus, $sessions)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR, Role::MANAGER)
        ;

        $this->assertSame([Role::EDITOR, Role::MANAGER], $changed->roles());
        $this->assertSame([$user], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
    }

    public function testEditingANonAdministratorNeverConsultsTheAdministratorDirectory(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;

        $this->assertSame([], $directory->askedWithout);
    }

    public function testWideningTheSoleAdministratorsRolesIsAllowedAndSkipsTheGuard(): void
    {
        // The directory holds this user as the ONLY active administrator, so an unconditional guard would
        // refuse here. Keeping ADMIN removes nobody from the pool, so the question is never put.
        $user = UserMother::create(roles: [Role::ADMIN]);
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::ADMIN, Role::EDITOR)
        ;

        $this->assertSame([Role::ADMIN, Role::EDITOR], $changed->roles());
        $this->assertSame([], $directory->askedWithout);
    }

    public function testDemotingANonActiveAdministratorSkipsTheGuard(): void
    {
        // A suspended identity is not in the active-administrator pool, so dropping its ADMIN drains nothing.
        $user = UserMother::create(roles: [Role::ADMIN]);
        $user->suspend();
        $user->pullDomainEvents();

        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::VIEWER)
        ;

        $this->assertSame([Role::VIEWER], $changed->roles());
        $this->assertSame([], $directory->askedWithout);
    }

    public function testDemotingTheLastActiveAdministratorIsRejectedWithoutMutatingSavingOrRevoking(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->makeUseCase($repository, $directory, $eventBus, $sessions)
                ->run(UserMother::DEFAULT_ID, Role::EDITOR)
            ;
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // rejected before any mutation, save, event or revoke
        }

        $this->assertSame([UserMother::DEFAULT_ID], $directory->askedWithout);
        $this->assertSame([Role::ADMIN], $user->roles());
        $this->assertSame([], $user->pullDomainEvents());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $sessions->revokeAllCalls);
    }

    public function testTheTargetIsReadUnderARowLockEvenWhereTheGuardDoesNotApply(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);
        $repository = new InMemoryUserRepository($user);
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $this->makeUseCase($repository, $directory)->run(UserMother::DEFAULT_ID, Role::EDITOR);

        // The directory is not consulted on this path, so its lock cannot be what protects the write.
        $this->assertSame([UserMother::DEFAULT_ID], $repository->forUpdateCalls);
        $this->assertSame([], $directory->askedWithout);
    }

    public function testAGrantLandingBeforeTheLockedReadReclassifiesTheChangeAsADemotion(): void
    {
        // The caller aimed at a set it read as [VIEWER]; ADMIN is granted before the locked re-read resolves,
        // so the change the use case is really about to make is a demotion — and must be judged as one.
        $user = UserMother::create(roles: [Role::VIEWER]);
        $repository = new InMemoryUserRepository($user);
        $repository->onFindByIdForUpdate = static function () use ($user): void {
            $user->changeRoles(Role::ADMIN);
            $user->pullDomainEvents();
        };
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $this->expectException(LastActiveAdministratorProtected::class);

        $this->makeUseCase($repository, $directory)->run(UserMother::DEFAULT_ID, Role::EDITOR);
    }

    public function testDemotingAnAdministratorWhileOthersRemainIsAllowed(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase(new InMemoryUserRepository($user), $directory)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;

        $this->assertSame([Role::EDITOR], $changed->roles());
        $this->assertSame([UserMother::DEFAULT_ID], $directory->askedWithout);
    }

    public function testResendingTheSameSetInAnotherOrderChangesNothing(): void
    {
        $user = UserMother::create(roles: [Role::MANAGER, Role::AUDIT_READER]);
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $sessions = new InMemorySessionRepository();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $unchanged = $this->makeUseCase($repository, $directory, $eventBus, $sessions)
            ->run(UserMother::DEFAULT_ID, Role::AUDIT_READER, Role::MANAGER, Role::MANAGER)
        ;

        $this->assertSame([Role::MANAGER, Role::AUDIT_READER], $unchanged->roles());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        // The point of the short-circuit: a redundant save must not tear down live sessions.
        $this->assertSame([], $sessions->revokeAllCalls);
    }

    public function testADemotionIsRecordedInTheComplianceTrailWithBothRoleSets(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $audit = new RecordingAuditLogger();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $this->makeUseCase(new InMemoryUserRepository($user), $directory, audit: $audit)
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;

        // Without this row a role change leaves no attributable evidence anywhere — `User` stays out of the
        // write-capture CDC to keep `password_hash` out of the trail, the generic hook audits only GET, and
        // the event-store entry names no actor. The erasure refusal's whole premise is that this row exists.
        $this->assertCount(1, $audit->records);
        $this->assertSame('USER_ROLES_CHANGED', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);

        $resource = $audit->records[0]['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        // The type the identity context declares as denoting a natural person, so the same anonymiser pass
        // that clears the subject's actor axis also clears this row's resource axis.
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
    }

    public function testTheMetadataContractIsFrozenKeyForKeyAndCarriesNoCredentialOrEmail(): void
    {
        $user = UserMother::create(roles: [Role::MANAGER, Role::VIEWER]);
        $audit = new RecordingAuditLogger();

        $this->makeUseCase(
            new InMemoryUserRepository($user),
            new InMemoryActiveAdministratorDirectory([]),
            audit: $audit,
        )->run(UserMother::DEFAULT_ID, Role::EDITOR, Role::AUDIT_READER);

        // One assertion over the WHOLE array, because there is no central registry of audit-metadata schemas:
        // the exact keys, in canonical order, with canonical (sorted, deduped) values, are the contract. A
        // later `oldRoles`/`roles_before` rename, an extra key, or an unsorted set fails here rather than
        // silently producing a trail nobody can query the same way twice. `User` is not a CDC-audited entity
        // precisely because a field diff would carry the credential — this row must never reintroduce it.
        $this->assertCount(1, $audit->records);
        $this->assertSame(
            ['previous_roles' => ['MANAGER', 'VIEWER'], 'new_roles' => ['AUDIT_READER', 'EDITOR']],
            $audit->records[0]['metadata'],
        );
    }

    public function testARedundantSetWritesNoComplianceRow(): void
    {
        $user = UserMother::create(roles: [Role::EDITOR]);
        $audit = new RecordingAuditLogger();

        $this->makeUseCase(
            new InMemoryUserRepository($user),
            new InMemoryActiveAdministratorDirectory([]),
            audit: $audit,
        )->run(UserMother::DEFAULT_ID, Role::EDITOR);

        // The no-op short-circuit returns before mutating, so a redundant request must not pad the trail with
        // a change that never happened.
        $this->assertSame([], $audit->records);
    }

    public function testARefusedDemotionWritesNoComplianceRow(): void
    {
        $user = UserMother::create(roles: [Role::ADMIN]);
        $audit = new RecordingAuditLogger();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->makeUseCase(new InMemoryUserRepository($user), $directory, audit: $audit)
                ->run(UserMother::DEFAULT_ID, Role::EDITOR)
            ;
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // the guard refuses before the mutation, so nothing is recorded as having changed
        }

        $this->assertSame([], $audit->records);
    }

    public function testChangingTheRolesOfAMissingIdentityIsANotFound(): void
    {
        $this->expectException(UserNotFound::class);

        $this->makeUseCase(new InMemoryUserRepository(), new InMemoryActiveAdministratorDirectory([]))
            ->run(UserMother::DEFAULT_ID, Role::EDITOR)
        ;
    }

    private function makeUseCase(
        InMemoryUserRepository $repository,
        InMemoryActiveAdministratorDirectory $directory,
        ?RecordingEventBus $eventBus = null,
        ?InMemorySessionRepository $sessions = null,
        ?RecordingAuditLogger $audit = null,
    ): ChangeUserRoles {
        return new ChangeUserRoles(
            $repository,
            $directory,
            $this->revokeSessions($sessions ?? new InMemorySessionRepository()),
            $eventBus ?? new RecordingEventBus(),
            $audit ?? new RecordingAuditLogger(),
            new InlineTransactionManager(),
        );
    }

    private function revokeSessions(InMemorySessionRepository $sessions): RevokeSessionsBestEffort
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-19T12:00:00+00:00'));

        return new RevokeSessionsBestEffort(
            new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
            new NullLogger(),
        );
    }
}
