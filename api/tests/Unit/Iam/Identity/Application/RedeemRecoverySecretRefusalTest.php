<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which identities the redemption admits, what every death case looks like from outside, and what a refusal
 * is allowed to leave behind.
 *
 * Separate from {@see RedeemRecoverySecretTest} because the subject is different: that class is about the
 * ORDER this use case takes its two locks in and what each concurrency outcome persists, while every case
 * here is about a refusal taken BEFORE any of that — plus the one axis of the containment rule that has no
 * schema to inspect.
 *
 * **The uniformity is the security property.** Six death cases answer one class and one title, so a dead
 * link, a wrong secret and a spent budget are byte-identical on the wire; the two identity walls are the
 * deliberate exception, reachable only by somebody who has already proven possession.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the arrange names the two repositories, the audit
 * projection, the session store, the transaction seam, the clock and the three refusals; each is what one of
 * the cases below is stated in terms of.
 */
#[CoversClass(RedeemRecoverySecret::class)]
final class RedeemRecoverySecretRefusalTest extends TestCase
{
    use RedeemsRecoverySecrets;

    #[Override]
    protected function setUp(): void
    {
        $this->initialiseHarness();
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

    /**
     * @param Closure(): User $identity
     */
    #[Test]
    #[DataProvider('provideAValidSecretOverAnUnadmittedIdentityIsRefusedWithoutConsumingItCases')]
    public function aValidSecretOverAnUnadmittedIdentityIsRefusedWithoutConsumingIt(Closure $identity): void
    {
        $user = $identity();
        $users = new InMemoryUserRepository($user);
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);

        $this->expectException(AccountDeactivated::class);

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            // Same shape as the suspended sibling: refused BEFORE the login, so the row survives and there is
            // no committed session to compensate for.
            $this->assertSame([], $secrets->removed);
            $this->assertSame([], $this->signedIn);
            $this->assertSame([], $this->sessions->revokeAllCalls);
        }
    }

    /**
     * @return iterable<string, array{Closure(): User}>
     */
    public static function provideAValidSecretOverAnUnadmittedIdentityIsRefusedWithoutConsumingItCases(): iterable
    {
        // The three states behind the OTHER wall. `AccountSuspended` had three cases and its twin — which
        // covers more states than it does — had none, on any of the four endpoints. The PWA meanwhile builds
        // a whole terminal wall for `account-deactivated` and proves it against a double, so nothing in the
        // tree asserted that this side ever produces the type that wall is keyed on.
        yield 'deactivated' => [static function (): User {
            $user = UserMother::create();
            $user->deactivate();
            $user->pullDomainEvents();

            return $user;
        }];
        yield 'invited, never activated' => [static fn (): User => UserMother::invited()];
        yield 'invitation revoked' => [static fn (): User => UserMother::revoked()];
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

    #[Test]
    public function noLogRecordThisPathCanEmitCarriesTheSelector(): void
    {
        // The fourth axis of the containment rule, and the one that had no witness. A log line has no schema
        // to inspect and no erasure owner, and the selector is a denial capability: whoever reads one holds
        // this account's recovery channel shut without authenticating as anybody.
        //
        // Both best-effort collaborators are driven to their `catch`, because a logger nothing reaches proves
        // nothing — the record count is asserted first for exactly that reason.
        $this->auditLogger->failOnLog = true;
        $this->sessions->onRevokeAll = static function (): never {
            throw new RuntimeException('Session store unavailable.');
        };

        $users = new InMemoryUserRepository($this->lockedUser());
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets, UserMother::DEFAULT_ID);
        $secrets->onLockedRead = static function () use ($secrets, $generated): void {
            $secrets->remove($generated->secret);
        };

        $selector = (string) $generated->secret->getId();

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $this->useCase($users, $secrets)->redeem($generated->plaintext(), $this->sessionSeam());
        } finally {
            $this->assertCount(
                2,
                $this->logger->records,
                'neither best-effort catch was reached, so this case asserts containment over an empty sink',
            );

            foreach ($this->logger->records as $record) {
                // The whole record, message and context together: a context value is `json_encode`d into the
                // line by the handler, and the message half is where an interpolated identifier would sit.
                $this->assertStringNotContainsString(
                    $selector,
                    \json_encode($record, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'the selector reached a log line, a sink with no TTL and no erasure owner',
                );
                $this->assertStringNotContainsString(
                    $generated->plaintext(),
                    \json_encode($record, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'the presented credential reached a log line',
                );
            }
        }
    }
}
