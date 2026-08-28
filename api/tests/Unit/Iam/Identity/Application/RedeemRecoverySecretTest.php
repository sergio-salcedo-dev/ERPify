<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRedeemed;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private const string NOW = '2026-08-28T12:00:00+00:00';

    /**
     * Whatever the use case handed the session seam, in order. Every case reads it — the ones that assert
     * a session WAS established, and the ones whose point is that it was not.
     *
     * @var list<string>
     */
    private array $signedIn = [];

    /**
     * The session store the compensating revoke reaches. A walled identity must not walk away with the
     * session the login already committed, so the cases below assert on what this recorded.
     */
    private InMemorySessionRepository $sessions;

    #[Override]
    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionRepository();
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
        // I-B, and it is what makes the absence of an ABBA cycle demonstrable rather than hoped for: minting
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
    public function aValidSecretOverAWalledIdentityIsRefusedWithoutConsumingIt(): void
    {
        // The one IDENTIFIED refusal on this endpoint. The presenter has already proven possession, so
        // telling them the account is suspended reveals nothing they could not learn by redeeming a working
        // one — and the row stays live for an attempt after the account is reinstated.
        $user = UserMother::create();
        $user->suspend();
        $user->pullDomainEvents();

        $users = new InMemoryUserRepository($user);
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);

        $this->expectException(AccountSuspended::class);

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            $this->assertSame([], $secrets->removed);
            // Refused BEFORE the login runs, so there is no session to compensate for — and asserting that
            // is what keeps this case distinct from its sibling below, where the wall arrives too late.
            $this->assertSame([], $this->signedIn);
            $this->assertSame([], $this->sessions->revokeAllCalls);
        }
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
                [UserMother::DEFAULT_ID],
                $this->sessions->revokeAllCalls,
                'the walled identity kept the session the redemption had already established',
            );
        }
    }

    #[Test]
    public function everyDeathCaseRaisesTheSameRefusal(): void
    {
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $selector = $generated->secret->getId() ?? '';

        foreach (
            [
                'no separator' => 'not-a-presentation',
                'empty selector' => '.secret',
                'empty secret' => $selector . '.',
                'malformed selector' => 'not-a-uuid.secret',
                'unknown selector' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4aaa.secret',
                'wrong secret' => $selector . '.wrong',
            ] as $case => $presented
        ) {
            try {
                $this->useCase($users, $secrets)->redeem($presented, $this->sessionSeam());
                $this->fail(\sprintf('"%s" was not refused.', $case));
            } catch (InvalidRecoverySecret $refusal) {
                // One class, and one title: the reason never travels, so a dead link and a wrong secret are
                // byte-identical on the wire.
                $this->assertSame('invalid-token', $refusal->type(), $case);
            }
        }

        $this->assertSame([], $secrets->removed, 'a death case consumed a row');
    }

    /**
     * The seam the HTTP adapter fills with `Security::login()`. It records rather than ignores, so a case
     * whose point is that no session was minted can assert that instead of trusting the absence of a
     * side effect it never observed.
     *
     * @return Closure(string): void
     */
    private function sessionSeam(): Closure
    {
        return function (string $email): void {
            $this->signedIn[] = $email;
        };
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryRecoverySecretRepository $secrets,
        ?RecordingEventBus $eventBus = null,
    ): RedeemRecoverySecret {
        return new RedeemRecoverySecret(
            $users,
            $secrets,
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            new RevokeSessionsBestEffort(
                new RevokeAllSessions(
                    $this->sessions,
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
                    FixedClock::at(self::NOW),
                ),
                new NullLogger(),
            ),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );
    }

    private function mintFor(
        InMemoryRecoverySecretRepository $secrets,
        string $userId,
    ): GeneratedRecoverySecret {
        $generated = RecoverySecret::mint($userId, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        $secrets->save($generated->secret);

        return $generated;
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }
}
