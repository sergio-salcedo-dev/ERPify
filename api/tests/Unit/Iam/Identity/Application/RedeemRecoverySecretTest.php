<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRedeemed;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The redemption's ordering and its four concurrency outcomes.
 *
 * **What these can and cannot prove.** The image has no `pcntl` and no procedural `pgsql`, so two
 * transactions cannot be made to race inside one PHPUnit process — the same limit
 * {@see \Erpify\Tests\Functional\Iam\Identity\ErasureLockOrderFunctionalTest} states for the erasure chain.
 * What is measurable here is the thing a race would decide: WHERE each decision is taken relative to the
 * lock, and what the loser does. A rival transaction is staged at the only instant it can matter — the locked
 * re-read — and the assertion is that the decision follows it rather than a snapshot taken before it. That
 * the adapters really take those locks is the functional sibling's claim, not this file's.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the arrange is the whole point here: two repositories,
 * the audit projection, the event bus, the transaction seam, the clock, the aggregate, the two refusals and
 * the lock journal are each what one of the six outcomes is stated in terms of.
 */
#[CoversClass(RedeemRecoverySecret::class)]
final class RedeemRecoverySecretTest extends TestCase
{
    use RedeemsRecoverySecrets;

    #[Override]
    protected function setUp(): void
    {
        $this->initialiseHarness();
    }

    #[Test]
    public function aValidSecretEstablishesTheSessionClearsTheLockoutAndRetiresTheRow(): void
    {
        $user = $this->lockedUser();
        $users = new InMemoryUserRepository($user);
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $eventBus = new RecordingEventBus();

        $this->useCase($users, $secrets, $eventBus)->redeem($generated->plaintext(), $this->sessionSeam());

        $this->assertSame([UserMother::DEFAULT_EMAIL], $this->signedIn);
        $this->assertCount(1, $secrets->removed);
        $this->assertCount(1, $users->saved, 'the identity was never written, so nothing lifted the lockout');
        $this->assertFalse(
            $users->saved[0]->isLockedAt(new DateTimeImmutable(self::NOW)),
            'the lockout the redemption exists to lift is still standing',
        );
        // Published inside the same transaction as the retire, so the durable record of the redemption
        // cannot exist without the consumption and the consumption cannot commit without it.
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(RecoverySecretRedeemed::class, $eventBus->publishedEvents[0]);
        $this->assertSame(UserMother::DEFAULT_ID, $eventBus->publishedEvents[0]->aggregateId());
    }

    #[Test]
    public function theUserRowIsLockedBeforeTheSecretRow(): void
    {
        // The acquisition order, and it is what makes the absence of an ABBA cycle demonstrable rather than
        // hoped for: minting
        // takes the same pair this way round, and recording a failed login takes the user row alone. No path
        // anywhere reaches the secret ahead of the user, so the wait-for graph has no cycle to close.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $journal = new LockOrderJournal();
        $users->lockOrderJournal = $journal;
        $secrets->lockOrderJournal = $journal;

        $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());

        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER, LockOrderJournal::RECOVERY_SECRET],
            $journal->crossTableOrder(),
        );
    }

    #[Test]
    public function aRivalRedemptionThatRetiresTheRowFirstLeavesThisOneWithTheOpaqueRefusal(): void
    {
        // Two redemptions of the same secret. The loser is staged as the one whose locked re-read finds the
        // row already gone — which is the only shape the loss can take once the decision is made under the
        // lock. It must consume nothing and answer exactly what a dead secret answers.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $secrets->onLockedRead = static function () use ($secrets, $generated): void {
            $secrets->remove($generated->secret);
        };
        $eventBus = new RecordingEventBus();

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $this->useCase($users, $secrets, $eventBus)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            $this->assertSame([], $users->saved, 'the loser wrote to the identity it never consumed a secret for');
            $this->assertSame(
                [],
                $eventBus->publishedEvents,
                'the loser recorded a redemption it never persisted',
            );
        }
    }

    #[Test]
    public function aRevocationCommittingUnderTheLockIsNeverVerifiedAgainst(): void
    {
        // Redemption versus revocation. The row is revoked at the locked re-read, so the verify this flow
        // would otherwise run has nothing to run against — and that is the property, not merely the refusal:
        // a verify over a row somebody already revoked would mean the decision came from the pre-lock read.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $secrets->onLockedRead = static function () use ($secrets, $generated): void {
            $secrets->remove($generated->secret);
        };

        $this->expectException(InvalidRecoverySecret::class);

        $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
    }

    #[Test]
    public function aRevocationLandingMidRedemptionTakesTheSessionTheLoginAlreadyCommitted(): void
    {
        // The containment half of the case above, and the reason the compensation covers the opaque refusal
        // and not only the status walls. The login commits its `iam_session` row before the consuming
        // transaction opens, and the admission gate reads that row and never this table — so answering the
        // refusal while leaving the session standing would let whoever holds a leaked secret keep admitted
        // access to an account whose owner has just destroyed that secret and been told 204. Revocation is
        // the act of cutting a leaked recovery edge; it must not be a race retried until it lands.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $eventBus = new RecordingEventBus();
        $secrets->onLockedRead = static function () use ($secrets, $generated): void {
            $secrets->remove($generated->secret);
        };

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $this->useCase($users, $secrets, $eventBus)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            $this->assertSame([UserMother::DEFAULT_EMAIL], $this->signedIn, 'the session was never established');
            $this->assertSame(
                [],
                $this->activeSessionIds(UserMother::DEFAULT_ID),
                'the refusal was answered over a live session minted from the secret that was just revoked',
            );
            // No consumption persisted, so the redeemed projection is unreachable and the domain event died
            // with the rolled-back transaction. Without the compensation row the interleaving leaves an
            // admitted-then-revoked session that nothing in the trail attributes to this channel.
            $this->assertSame(
                ['RECOVERY_SECRET_REDEMPTION_COMPENSATED'],
                $this->auditedActions(),
                'the compensated redemption left no trace, or claimed a consumption that never persisted',
            );
            $this->assertSame([], $eventBus->publishedEvents, 'a redemption that consumed nothing published');
        }
    }

    /**
     * The property the coarse compensation did not have, and the reason it was replaced.
     *
     * The owner is signed in and — this is the part that makes it sharp — LOCKED OUT, which is the state this
     * whole channel exists for. They revoke a leaked secret from their profile while the holder's redemption is
     * mid-flight. The holder's locked pass then finds the row gone and compensates. Under a revoke of every
     * session of the identity, the owner loses their session too: no secret, no session, and no login, because
     * `locked_until` is still in the future.
     */
    #[Test]
    public function theCompensationTakesTheRedemptionsOwnSessionAndSparesTheOwnersLiveOne(): void
    {
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);

        // The owner's session, minted before the redemption starts and never part of it.
        $ownersSession = $this->mintSession(UserMother::DEFAULT_ID);

        $secrets->onLockedRead = static function () use ($secrets, $generated): void {
            $secrets->remove($generated->secret);
        };

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            // Exactly one survivor, and it is the owner's. Asserting the SET rather than a call count is what
            // separates a precise revoke from a coarse one that happened to run once.
            $this->assertSame(
                [$ownersSession->toString()],
                $this->activeSessionIds(UserMother::DEFAULT_ID),
                'the compensation reached past its own session and signed the owner out',
            );
        }
    }

    #[Test]
    public function aFailureToEstablishTheSessionLeavesTheSecretEntirelyIntact(): void
    {
        // The whole reason the login runs BEFORE the consume. Inverted, this failure would leave the secret
        // spent, the row gone and a signed-out sole administrator with nothing left to present.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);

        try {
            $this->useCase($users, $secrets)->redeem(
                $generated->plaintext(),
                static fn (string $email): never => throw new RuntimeException(
                    \sprintf('the session store is unreachable for %s', $email),
                ),
            );
            $this->fail('Expected the session failure to propagate.');
        } catch (RuntimeException) {
            // propagates to the adapter, which answers 503
        }

        $this->assertSame([], $this->signedIn, 'the seam reported a session it had just refused to mint');
        $this->assertSame([], $secrets->removed);
        $this->assertSame([], $users->saved);
        $this->assertInstanceOf(
            RecoverySecret::class,
            $secrets->findBySelector($generated->secret->getId() ?? ''),
            'the secret must survive a session that was never established',
        );
    }

    #[Test]
    public function aSessionThatSucceededBeforeAFailedMutationLeavesTheSecretRedeemable(): void
    {
        // The partial state the design accepts and names: three state machines, no transaction spanning them.
        // The session is NOT rolled back — the owner has recovered access, which is the objective — but the
        // endpoint does not get to answer 204, and the secret stays live so a second attempt completes the
        // persisted cleanup without anyone minting a new one.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $secrets->onRemove = static fn (): never => throw new RuntimeException('the retire failed to flush');

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
            $this->fail('Expected the failed mutation to propagate.');
        } catch (RuntimeException) {
            // reaches the client as a 5xx; the session it already minted stands
        }

        $this->assertSame([UserMother::DEFAULT_EMAIL], $this->signedIn, 'the session was not established');
        $this->assertInstanceOf(
            RecoverySecret::class,
            $secrets->findBySelector($generated->secret->getId() ?? ''),
            'the secret must stay redeemable so the retry needs no fresh mint',
        );
    }

    #[Test]
    public function anIdentityWalledUNDERTheLockLosesTheSessionTheLoginAlreadyCommitted(): void
    {
        // The race the pre-login check cannot see: the identity is admissible when the session is minted and
        // walled by the time the consuming transaction reads it under the lock. `Security::login()` has by
        // then inserted and COMMITTED the `iam_session` row, and the admission gate reads that row and never
        // the identity's status — so a 403 body over a live session would leave an administrator's
        // suspension defeated by a race an attacker can simply retry until it lands. The status re-read
        // refuses the consumption; only the compensating revoke refuses the session.
        $user = UserMother::create();
        $users = new InMemoryUserRepository($user);
        $users->onFindByIdForUpdate = static function () use ($user): void {
            $user->suspend();
            $user->pullDomainEvents();
        };
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);

        $this->expectException(AccountSuspended::class);

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            $this->assertSame([UserMother::DEFAULT_EMAIL], $this->signedIn, 'the session was never established');
            $this->assertSame([], $secrets->removed, 'the walled redemption consumed the secret');
            $this->assertSame(
                [],
                $this->activeSessionIds(UserMother::DEFAULT_ID),
                'the walled identity kept the session the redemption had already established',
            );
        }
    }
}
