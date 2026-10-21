<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a redemption does to the identity's OTHER sessions: it evicts them in the transaction that consumes the
 * secret, keeps only the one it established, and withholds the consumption whenever that one cannot be kept.
 *
 * A subject of its own, beside the ordering and concurrency claims in {@see RedeemRecoverySecretTest} and the
 * admission claims in {@see RedeemRecoverySecretRefusalTest}: those are about the secret and the identity,
 * this is about the session set, and each outcome here is stated as WHICH sessions survive rather than as a
 * call count — a count cannot tell a precise eviction from one that also took the session it had to keep.
 *
 * @internal
 */
#[CoversClass(RedeemRecoverySecret::class)]
final class RedeemRecoverySecretEvictionTest extends TestCase
{
    use RedeemsRecoverySecrets;

    #[Override]
    protected function setUp(): void
    {
        $this->initialiseHarness();
    }

    /**
     * The property the redemption owes the owner it recovers: afterwards their session is the ONLY one alive.
     * Any other session — a device they forgot, or one an attacker stole — would otherwise survive the
     * redemption and could revoke the recovered session in a loop through "sign out my other devices", which
     * carries no budget, leaving the owner locked out with the secret already spent.
     */
    #[Test]
    public function aRedemptionEvictsEveryOtherSessionAndKeepsOnlyTheOneItEstablished(): void
    {
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $stolen = $this->mintSession(UserMother::DEFAULT_ID);
        $alsoStolen = $this->mintSession(UserMother::DEFAULT_ID);
        $eventBus = new RecordingEventBus();

        $this->useCase($users, $secrets, $eventBus)->redeem($generated->plaintext(), $this->sessionSeam());

        $redeemed = $this->currentSession->get();
        $this->assertInstanceOf(SessionId::class, $redeemed);
        $this->assertFalse($redeemed->equals($stolen), 'the seam never minted a session of its own');
        $this->assertFalse($redeemed->equals($alsoStolen), 'the seam never minted a session of its own');
        $this->assertSame(
            [$redeemed->toString()],
            $this->activeSessionIds(UserMother::DEFAULT_ID),
            'a session that predates the redemption survived it, or the redemption evicted its own',
        );
        $evicted = $eventBus->publishedEvents[0] ?? null;
        $this->assertInstanceOf(OtherSessionsRevoked::class, $evicted);
        $this->assertSame($redeemed->toString(), $evicted->keptSessionId());
    }

    /**
     * The interleaving the eviction closes, fired a moment early: a session the redemption is about to evict
     * revokes the redeemed one first. Consuming here would spend the secret over an owner holding no session —
     * the exact lockout this channel ends. So the rest are evicted on the strength of the secret verified under
     * lock, the secret is NOT consumed, and the caller is told to retry.
     */
    #[Test]
    public function aRedeemedSessionRevokedBeforeTheLockEvictsTheRestAndLeavesTheSecretLive(): void
    {
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $stolen = $this->mintSession(UserMother::DEFAULT_ID);
        $sessions = $this->sessions;
        $sessions->beforeLockActive = static function () use ($sessions, $stolen): void {
            // The stolen session's "sign out my other devices", committing while the redemption waits.
            $sessions->revokeOthersForUser(UserMother::DEFAULT_ID, $stolen);
        };

        $eventBus = new RecordingEventBus();

        try {
            $this->useCase($users, $secrets, $eventBus)->redeem($generated->plaintext(), $this->sessionSeam());
            $this->fail('Expected the redemption to withhold the consumption and ask for a retry.');
        } catch (TransientTransactionFailure) {
            // 503: the identical presentation is expected to succeed on the next attempt
        }

        $this->assertSame(
            [],
            $this->activeSessionIds(UserMother::DEFAULT_ID),
            'the session that interfered survived, so the retry would meet the same interference',
        );
        $this->assertInstanceOf(
            RecoverySecret::class,
            $secrets->findBySelector($generated->secret->getId() ?? ''),
            'the secret was spent over an owner left holding no session',
        );
        $this->assertSame([], $users->saved, 'the lockout was lifted by a redemption that consumed nothing');
        // Nothing was kept, so the fact is the full revocation — an `OtherSessionsRevoked` would name a revoked
        // session as spared — and the audit row is what attributes that eviction to the recovery channel.
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(AllSessionsRevoked::class, $eventBus->publishedEvents[0]);
        $this->assertSame(
            ['RECOVERY_SECRET_REDEMPTION_INTERRUPTED'],
            $this->auditedActions(),
            'a valid presentation signed every device out and the security trail does not say so',
        );

        // And the retry the answer invites does complete: nothing left alive can revoke the next session.
        $sessions->beforeLockActive = null;
        $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());

        $this->assertCount(1, $this->activeSessionIds(UserMother::DEFAULT_ID));
        $this->assertNull($secrets->findBySelector($generated->secret->getId() ?? ''));
    }

    /**
     * Unlike every other session collaborator in this layer the eviction is not best-effort: nothing
     * de-authenticates the other sessions after a redemption, which replaces no credential. So its failure
     * must withhold the consumption — leaving the owner the session they just established and a secret they
     * can present again — rather than spend the secret over sessions still standing.
     */
    #[Test]
    public function anEvictionThatFailsWithholdsTheConsumptionAndKeepsTheRedeemedSession(): void
    {
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $this->sessions->beforeLockActive = static fn (): never => throw new RuntimeException('store unreachable');

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
            $this->fail('Expected the eviction failure to propagate.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('store unreachable', $runtimeException->getMessage());
        }

        $redeemed = $this->currentSession->get();
        $this->assertInstanceOf(SessionId::class, $redeemed);
        $this->assertSame([$redeemed->toString()], $this->activeSessionIds(UserMother::DEFAULT_ID));
        $this->assertInstanceOf(
            RecoverySecret::class,
            $secrets->findBySelector($generated->secret->getId() ?? ''),
            'the secret was spent although the other sessions were never evicted',
        );
        $this->assertSame([], $this->auditedActions());
    }

    #[Test]
    public function aMissingCorrelationRefusesToEvictAndLeavesTheSecretLive(): void
    {
        // Widening into a revoke of every session is the answer this refuses: it would take the session the
        // login just minted and then spend the secret over an owner left with nothing.
        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $bystander = $this->mintSession(UserMother::DEFAULT_ID);
        $this->currentSession = new RecordingCurrentSessionReference();

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeamWithoutCorrelation());
            $this->fail('Expected a redemption with no session to keep to refuse the eviction.');
        } catch (LogicException) {
            // a 500: unreachable from a login that succeeded, and loud if it ever is
        }

        $this->assertSame([$bystander->toString()], $this->activeSessionIds(UserMother::DEFAULT_ID));
        $this->assertInstanceOf(RecoverySecret::class, $secrets->findBySelector($generated->secret->getId() ?? ''));
    }
}
