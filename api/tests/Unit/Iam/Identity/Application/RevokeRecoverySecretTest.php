<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRevoked;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Revocation: the visible eviction that pays for never destroying a secret silently, and what it costs to
 * reach.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — measured at 18 against a threshold of 13, and what the
 * number counts is the endpoint's own vocabulary: the two repositories, the credential proof, the audit
 * projection, the event bus, the transaction seam, the aggregate and its secret, and the two refusals this
 * act can answer with. Each is what one of the cases below is stated in terms of, so collapsing any of them
 * would cost a case rather than a dependency.
 */
#[CoversClass(RevokeRecoverySecret::class)]
final class RevokeRecoverySecretTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function itRetiresTheRowUnderTheLockAndRecordsTheFact(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));
        $eventBus = new RecordingEventBus();
        $audit = new RecordingAuditLogger();

        $this->useCase($users, $secrets, $eventBus, $audit)
            ->revoke(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $secrets->removed);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(RecoverySecretRevoked::class, $eventBus->publishedEvents[0]);
        $this->assertCount(1, $audit->records);
        $this->assertSame('RECOVERY_SECRET_REVOKED', $audit->records[0]['action']);
    }

    #[Test]
    public function theUserRowIsLockedBeforeTheSecretRow(): void
    {
        // Proving the credential means reading the identity, so this flow holds the same PAIR of locks minting
        // and redemption hold — and a deadlock cycle needs two transactions taking that pair in opposite
        // orders. Taking the user first is what leaves no order to be opposite to.
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));
        $journal = new LockOrderJournal();
        $users->lockOrderJournal = $journal;
        $secrets->lockOrderJournal = $journal;

        $this->useCase($users, $secrets)->revoke(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());

        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER, LockOrderJournal::RECOVERY_SECRET],
            $journal->crossTableOrder(),
            'revoking must take the same pair in the same order minting and redemption do, or the two deadlock',
        );
    }

    #[Test]
    public function aWrongCurrentPasswordRefusesAndTheSecretSurvives(): void
    {
        // The whole point of the proof: a stolen session may not spend one request destroying the credential
        // that is its owner's way back in. The row must still be there afterwards, and nothing may be recorded
        // as revoked.
        $users = new InMemoryUserRepository(UserMother::create());
        $secret = $this->secretFor(UserMother::DEFAULT_ID);
        $secrets = new InMemoryRecoverySecretRepository($secret);
        $eventBus = new RecordingEventBus();
        $audit = new RecordingAuditLogger();

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $this->useCase($users, $secrets, $eventBus, $audit)
                ->revoke(UserMother::DEFAULT_ID, $this->refusesTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $secrets->removed);
            $this->assertSame($secret, $secrets->findByUserId(UserMother::DEFAULT_ID));
            $this->assertSame([], $eventBus->publishedEvents);
            $this->assertSame([], $audit->records);
        }
    }

    #[Test]
    public function aWrongCurrentPasswordIsRefusedBeforeTheExistenceOfASecretIsConsulted(): void
    {
        // The ORDER is the security property, and it is the same one minting states: answering differently to
        // someone who has not re-proved the credential would turn a stolen session into an oracle over whether
        // a recovery secret exists. The refusal must therefore fire without the secret row ever being read.
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));
        $journal = new LockOrderJournal();
        $users->lockOrderJournal = $journal;
        $secrets->lockOrderJournal = $journal;

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $this->useCase($users, $secrets)->revoke(UserMother::DEFAULT_ID, $this->refusesTheCurrentPassword());
        } finally {
            $this->assertSame(
                [LockOrderJournal::IDENTITY_USER],
                $journal->crossTableOrder(),
                'the secret row was consulted by a caller who had not proved the credential',
            );
        }
    }

    #[Test]
    public function revokingNothingIsSuccessfulAndLeavesNoRecordOfARevocation(): void
    {
        // Idempotent by construction — the caller has proved their credential by the time this answer is
        // reached, so an empty revocation discloses nothing. The audit row is conditioned on a row actually
        // going: a `security` entry for an act that never happened is a false statement in the trail.
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository();
        $eventBus = new RecordingEventBus();
        $audit = new RecordingAuditLogger();

        $this->useCase($users, $secrets, $eventBus, $audit)
            ->revoke(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertSame([], $secrets->removed);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $audit->records);
    }

    #[Test]
    public function anIdentityThatResolvesToNoRowIsARefusalRatherThanAnEmptySuccess(): void
    {
        // A session outliving its identity is a 404, not a silent 204: the credential cannot be proved against
        // a row that is not there, and answering success would report a revocation nothing performed.
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));

        $this->expectException(UserNotFound::class);

        try {
            $this->useCase(new InMemoryUserRepository(), $secrets)
                ->revoke(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $secrets->removed);
        }
    }

    #[Test]
    public function itRevokesOnlyTheCallersOwnSecret(): void
    {
        $mine = $this->secretFor(UserMother::DEFAULT_ID);
        $theirs = $this->secretFor('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99');
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository($mine, $theirs);

        $this->useCase($users, $secrets)->revoke(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());

        $this->assertSame([$mine], $secrets->removed);
        $this->assertInstanceOf(RecoverySecret::class, $secrets->findByUserId('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99'));
    }

    /**
     * Stands in for the hasher comparison the HTTP adapter supplies. It READS the stored credential rather
     * than ignoring it, which is the shape the real closure has: one that took no argument would pass this
     * suite while proving nothing about a use case that stopped handing the credential over.
     *
     * @return Closure(HashedPassword): bool
     */
    private function acceptsTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' !== $stored->toString();
    }

    /**
     * The same seam refusing — handed the same stored credential, answering the other way.
     *
     * @return Closure(HashedPassword): bool
     */
    private function refusesTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' === $stored->toString();
    }

    private function secretFor(string $userId): RecoverySecret
    {
        $generated = RecoverySecret::mint($userId, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryRecoverySecretRepository $secrets,
        ?RecordingEventBus $eventBus = null,
        ?RecordingAuditLogger $audit = null,
    ): RevokeRecoverySecret {
        return new RevokeRecoverySecret(
            $users,
            $secrets,
            new ProveCurrentPassword(),
            new RecordRecoverySecretAuditBestEffort($audit ?? new RecordingAuditLogger(), new NullLogger()),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
        );
    }
}
