<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\UnlockUserAccount;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\SelfUnlockForbidden;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The lever `#602`'s design review demanded and the two properties it must never lose: the self-unlock refusal
 * runs before any row is touched or any audit entry is written, and the domain's own idempotency signal
 * ({@see User::clearLockout()} returning `false` on an already-clear
 * identity) reaches the caller rather than being discarded in favour of an unconditional "success".
 *
 * Object coupling sits one above the default threshold, and for the same reason {@see ChangeUserRolesTest}'s
 * does: the collaborators are the doubles for every seam the use case actually has (repository, audit logger,
 * actor context, transaction manager) plus the entity mother and the two exceptions the surface under test can
 * raise — not a class doing several jobs.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UnlockUserAccount::class)]
final class UnlockUserAccountTest extends TestCase
{
    private const string ACTING_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    public function testUnlockingAGenuinelyLockedIdentityClearsItAndReportsMutation(): void
    {
        $user = UserMother::create();
        $this->lockOut($user);
        $repository = new InMemoryUserRepository($user);
        $audit = new RecordingAuditLogger();

        $result = $this->makeUseCase($repository, $audit)->run(UserMother::DEFAULT_ID);

        $this->assertTrue($result->unlocked);
        $this->assertSame($user, $result->user);
        $this->assertSame([$user], $repository->saved);
    }

    public function testUnlockingAnAlreadyUnlockedIdentityReportsNoMutationButStillSaves(): void
    {
        // clearLockout() is a no-op on a clean identity, so save() is correctly skipped — this pins that the
        // use case never claims a mutation the aggregate itself did not report.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        $result = $this->makeUseCase($repository)->run(UserMother::DEFAULT_ID);

        $this->assertFalse($result->unlocked);
        $this->assertSame([], $repository->saved);
    }

    public function testTheAuditRowIsWrittenOnAGenuineRecoveryWithTheMutatedFlagTrue(): void
    {
        $user = UserMother::create();
        $this->lockOut($user);
        $audit = new RecordingAuditLogger();

        $this->makeUseCase(new InMemoryUserRepository($user), $audit)->run(UserMother::DEFAULT_ID);

        $this->assertCount(1, $audit->records);
        $this->assertSame('ACCOUNT_UNLOCKED_BY_ADMIN', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);
        $this->assertSame(['unlocked' => true], $audit->records[0]['metadata']);

        $resource = $audit->records[0]['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
    }

    public function testTheAuditRowIsAlsoWrittenOnANoOpWithTheMutatedFlagFalse(): void
    {
        // Unlike ChangeUserRoles, which skips its compliance row on a redundant no-op (nothing changed worth
        // restating), this row records that the lever was INVOKED — that fact holds regardless of the outcome.
        $user = UserMother::create();
        $audit = new RecordingAuditLogger();

        $this->makeUseCase(new InMemoryUserRepository($user), $audit)->run(UserMother::DEFAULT_ID);

        $this->assertCount(1, $audit->records);
        $this->assertSame(['unlocked' => false], $audit->records[0]['metadata']);
    }

    public function testAnActorCannotUnlockTheirOwnIdentity(): void
    {
        $user = UserMother::create(id: self::ACTING_ADMIN_ID);
        $this->lockOut($user);
        $repository = new InMemoryUserRepository($user);
        $audit = new RecordingAuditLogger();

        $useCase = $this->makeUseCase(
            $repository,
            $audit,
            ActorContext::forUser(self::ACTING_ADMIN_ID),
        );

        try {
            $useCase->run(self::ACTING_ADMIN_ID);
            $this->fail('Expected SelfUnlockForbidden.');
        } catch (SelfUnlockForbidden) {
            // refused before the transaction opens — no row is locked, mutated or saved
        }

        $this->assertSame([], $repository->forUpdateCalls);
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $audit->records);
    }

    public function testSelfUnlockIsRefusedEvenUnderADifferentUuidCasing(): void
    {
        // ACTING_ADMIN_ID carries hex letters, so upper-casing it yields the same UUID in a different case —
        // Uuid::ensure accepts it, and the refusal must not be bypassable by re-casing the route id.
        $upperCasedOwnId = \strtoupper(self::ACTING_ADMIN_ID);
        $user = UserMother::create(id: self::ACTING_ADMIN_ID);
        $repository = new InMemoryUserRepository($user);

        $useCase = $this->makeUseCase($repository, actor: ActorContext::forUser(self::ACTING_ADMIN_ID));

        try {
            $useCase->run($upperCasedOwnId);
            $this->fail('Expected SelfUnlockForbidden.');
        } catch (SelfUnlockForbidden) {
            // a case-flipped id is still the actor's own identity
        }

        $this->assertSame([], $repository->forUpdateCalls);
    }

    public function testAnAdministratorMayUnlockAnotherIdentity(): void
    {
        // The negative case's mirror: a distinct target id must never trip the self-unlock guard.
        $target = UserMother::create();
        $this->lockOut($target);
        $repository = new InMemoryUserRepository($target);

        $result = $this->makeUseCase($repository, actor: ActorContext::forUser(self::ACTING_ADMIN_ID))
            ->run(UserMother::DEFAULT_ID)
        ;

        $this->assertTrue($result->unlocked);
    }

    public function testAnOffRequestSystemActorNeverTripsTheSelfUnlockGuard(): void
    {
        // The CLI's `system` actor carries no id, so `strcasecmp(null, ...)` must never be reached — the
        // guard's own null check is what keeps an off-request caller from being refused as "itself".
        $user = UserMother::create();
        $this->lockOut($user);
        $repository = new InMemoryUserRepository($user);

        $result = $this->makeUseCase($repository, actor: ActorContext::system())->run(UserMother::DEFAULT_ID);

        $this->assertTrue($result->unlocked);
    }

    public function testUnlockingAMissingIdentityIsANotFound(): void
    {
        $this->expectException(UserNotFound::class);

        $this->makeUseCase(new InMemoryUserRepository())->run(UserMother::DEFAULT_ID);
    }

    public function testAMalformedIdIsRejectedBeforeAnyWork(): void
    {
        $repository = new InMemoryUserRepository();

        $this->expectException(InvalidUuidException::class);

        try {
            $this->makeUseCase($repository)->run('not-a-uuid');
        } finally {
            $this->assertSame([], $repository->forUpdateCalls);
        }
    }

    private function lockOut(User $user): void
    {
        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }

        $user->pullDomainEvents();
    }

    private function makeUseCase(
        InMemoryUserRepository $repository,
        ?RecordingAuditLogger $audit = null,
        ?ActorContext $actor = null,
    ): UnlockUserAccount {
        return new UnlockUserAccount(
            $repository,
            $audit ?? new RecordingAuditLogger(),
            new FixedActorContextFactory($actor ?? ActorContext::forUser(self::ACTING_ADMIN_ID)),
            new InlineTransactionManager(),
        );
    }
}
