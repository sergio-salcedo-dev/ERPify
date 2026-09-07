<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeMyPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\NewPasswordMustDiffer;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Throwable;

/**
 * Weaves the real {@see RevokeAllSessions} graph (with the Session context's in-memory store) rather than a
 * mock, since it is a `final` published seam — hence the coupling this contract legitimately carries.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
// The two refusal types are asserted here and nowhere else, so without naming them the clover discards
// coverage this suite genuinely produces and reports both classes at zero.
#[CoversClass(ChangeMyPassword::class)]
#[CoversClass(InvalidCurrentPassword::class)]
#[CoversClass(NewPasswordMustDiffer::class)]
final class ChangeMyPasswordTest extends TestCase
{
    private const string NEW_HASH = 'new-argon2id-hash';

    public function testReplacesTheCredentialRevokesEverySessionEmitsAndNotifies(): void
    {
        $user = UserMother::create();
        $users = new InMemoryUserRepository($user);
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordChangedEmailSender();
        $kdfRuns = 0;

        $this->useCase($users, $sessions, $eventBus, $emails)->change(
            UserMother::DEFAULT_ID,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            static function () use (&$kdfRuns): HashedPassword {
                ++$kdfRuns;

                return HashedPassword::fromHash(self::NEW_HASH);
            },
        );

        $this->assertSame(1, $kdfRuns);
        $this->assertSame(self::NEW_HASH, $user->passwordHash()?->toString());
        $this->assertSame([$user], $users->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame('erpify.iam.identity.password-changed', $eventBus->publishedEvents[0]::eventName());
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
        $this->assertSame([UserMother::DEFAULT_EMAIL], $emails->sentTo);
    }

    /**
     * The lock is what makes "is this still the stored credential?" read committed state rather than a snapshot
     * a concurrent credential write has already invalidated. A single-threaded harness cannot run the race, so
     * the assertion is that the locked re-fetch is the read the use case actually performs.
     */
    public function testTheChangeRunsUnderTheUserRowLock(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());

        $this->useCase($users)->change(
            UserMother::DEFAULT_ID,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $this->precomputedHash(),
        );

        $this->assertSame([UserMother::DEFAULT_ID], $users->forUpdateCalls);
    }

    public function testAWrongCurrentPasswordIsRefusedWithoutAnyEffect(): void
    {
        $this->assertRefusedWithoutEffect(
            InvalidCurrentPassword::class,
            $this->credentialDoesNotMatch(),
            $this->credentialDoesNotMatch(),
        );
    }

    public function testANewPasswordEqualToTheCurrentOneIsRefusedWithoutAnyEffect(): void
    {
        $this->assertRefusedWithoutEffect(
            NewPasswordMustDiffer::class,
            $this->credentialMatches(),
            $this->credentialMatches(),
        );
    }

    /**
     * The only identity that holds no credential is one still `INVITED`, so it meets the admission wall rather
     * than the credential comparison — and answers in the vocabulary that describes the actual reason, instead
     * of telling its owner the password they never set is wrong.
     */
    public function testACredentiallessIdentityIsWalledAsInadmissible(): void
    {
        $this->assertRefusedWithoutEffect(
            AccountDeactivated::class,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            UserMother::invited(),
            expectedKdfRuns: 0,
        );
    }

    /**
     * An `ACTIVE` identity whose stored hash the value object refuses. The `??` alone never reaches its own
     * throw here — `passwordHash()` raises first — so without the catch the marker-less exception leaves as a
     * 500 where this path plainly intends a refusal, on a route its owner reached holding a valid session.
     *
     * It answers `InvalidCurrentPassword` and not a new vocabulary because that is what is true from where the
     * caller stands: the credential they typed cannot be shown to match, and an unusable stored one is
     * indistinguishable from a wrong one to whoever is typing. The state is planted straight onto the property,
     * the only way persistence can produce it — the writer refuses `''` and always has.
     */
    public function testAnUnreadableStoredCredentialIsRefusedRatherThanRaising(): void
    {
        $user = UserMother::create();
        $property = new ReflectionProperty(User::class, 'passwordHash');
        $property->setValue($user, '');

        $this->assertRefusedWithoutEffect(
            InvalidCurrentPassword::class,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $user,
            expectedKdfRuns: 0,
        );
    }

    /**
     * The wall now sits immediately after the row lock, so a walled identity pays no KDF at all and hears the
     * same 403 the rest of the product speaks — not the aggregate's `InvalidIdentityTransition`, whose title
     * announces a lifecycle transition nobody asked for. Zero KDF runs is the load-bearing half of this: the
     * refusal is decided before either comparison closure is invoked.
     */
    public function testASuspendedIdentityIsWalledBeforeAnyComparison(): void
    {
        $suspended = UserMother::create();
        $suspended->suspend();
        $suspended->pullDomainEvents();

        $this->assertRefusedWithoutEffect(
            AccountSuspended::class,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $suspended,
            expectedKdfRuns: 0,
        );
    }

    /**
     * A live lockout is orthogonal to `ACTIVE`, so it does not wall the change — and the change clears it,
     * by an explicit decision in the use case rather than as a side effect of the login that follows.
     */
    public function testChangingTheCredentialClearsALiveLockout(): void
    {
        $now = $this->now();
        $locked = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $locked->recordFailedAttempt($now);
        }

        $locked->pullDomainEvents();
        $this->assertTrue($locked->isLockedAt($now), 'the arrangement must actually leave the identity locked');

        $this->useCase(new InMemoryUserRepository($locked))->change(
            UserMother::DEFAULT_ID,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $this->precomputedHash(),
        );

        $this->assertFalse($locked->isLockedAt($now));
    }

    public function testAnIdThatResolvesToNoIdentityIsANotFound(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $users->goneUnderLock = true;

        $emails = new RecordingPasswordChangedEmailSender();

        try {
            $this->useCase($users, emails: $emails)->change(
                UserMother::DEFAULT_ID,
                $this->credentialMatches(),
                $this->credentialDoesNotMatch(),
                $this->precomputedHash(),
            );
            $this->fail('Expected ' . UserNotFound::class);
        } catch (Throwable $throwable) {
            $this->assertInstanceOf(UserNotFound::class, $throwable);
        }

        $this->assertSame([], $emails->sentTo);
    }

    /**
     * The revoke tears down the session that issued the request too, so it must never run while the credential
     * change is still rollback-able. Each effect samples the transaction manager from INSIDE itself, so the
     * assertion fails the moment either one moves back across the boundary — sampling only at the send would
     * still read `committed` as true with the revoke moved inside the transaction, and prove nothing about it.
     */
    public function testBothEffectsRunAfterTheCommit(): void
    {
        $transactions = new InlineTransactionManager();
        $sessions = new InMemorySessionRepository();

        /** @var list<array{string, bool}> $committedAtEachEffect */
        $committedAtEachEffect = [];
        $sessions->onRevokeAll = static function () use ($transactions, &$committedAtEachEffect): void {
            $committedAtEachEffect[] = ['revoke', $transactions->committed];
        };
        $emails = new RecordingPasswordChangedEmailSender(
            sample: static function () use ($transactions, &$committedAtEachEffect): array {
                $committedAtEachEffect[] = ['email', $transactions->committed];

                return [];
            },
        );

        $this->useCase(sessions: $sessions, emails: $emails, transactions: $transactions)->change(
            UserMother::DEFAULT_ID,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $this->precomputedHash(),
        );

        $this->assertSame([['revoke', true], ['email', true]], $committedAtEachEffect);
    }

    /**
     * @param class-string<Throwable>       $expected
     * @param Closure(HashedPassword): bool $verifyCurrent
     * @param Closure(HashedPassword): bool $isSameAsCurrent
     */
    private function assertRefusedWithoutEffect(
        string $expected,
        Closure $verifyCurrent,
        Closure $isSameAsCurrent,
        ?User $user = null,
        int $expectedKdfRuns = 0,
    ): void {
        $users = new InMemoryUserRepository($user ?? UserMother::create());
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordChangedEmailSender();
        $kdfRuns = 0;

        try {
            $this->useCase($users, $sessions, $eventBus, $emails)->change(
                UserMother::DEFAULT_ID,
                $verifyCurrent,
                $isSameAsCurrent,
                static function () use (&$kdfRuns): HashedPassword {
                    ++$kdfRuns;

                    return HashedPassword::fromHash(self::NEW_HASH);
                },
            );
            $this->fail('Expected ' . $expected);
        } catch (Throwable $throwable) {
            $this->assertInstanceOf($expected, $throwable);
        }

        // A refusal writes no credential, publishes no fact, evicts no device and mails no security notice —
        // the four effects a change that did not happen must not be observable through. The KDF budget is
        // asserted too, because the two refusals an outsider can trigger have to be free of it.
        $this->assertSame($expectedKdfRuns, $kdfRuns);
        $this->assertSame([], $users->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $sessions->revokeAllCalls);
        $this->assertSame([], $emails->sentTo);
    }

    private function useCase(
        ?InMemoryUserRepository $users = null,
        ?InMemorySessionRepository $sessions = null,
        ?RecordingEventBus $eventBus = null,
        ?RecordingPasswordChangedEmailSender $emails = null,
        ?InlineTransactionManager $transactions = null,
    ): ChangeMyPassword {
        $clock = new FixedClock($this->now());

        return new ChangeMyPassword(
            $users ?? new InMemoryUserRepository(UserMother::create()),
            new ProveCurrentPassword(),
            new RevokeSessionsBestEffort(
                new RevokeAllSessions(
                    $sessions ?? new InMemorySessionRepository(),
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
                    $clock,
                ),
                new NullLogger(),
            ),
            new SendPasswordChangedEmailBestEffort(
                $emails ?? new RecordingPasswordChangedEmailSender(),
                new NullLogger(),
            ),
            $eventBus ?? new RecordingEventBus(),
            $transactions ?? new InlineTransactionManager(),
        );
    }

    /**
     * One instant for the whole fixture. The lockout arithmetic and the clock the revoke path runs on are
     * independent today; a literal repeated at both is how a pair stops being deliberately independent and
     * starts being accidentally equal.
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-04T12:00:00+00:00');
    }

    /**
     * Answers "yes" by actually comparing against the mother's credential, so the trio of closures also pins
     * that what the use case hands them is the STORED hash and not some other value.
     *
     * @return Closure(HashedPassword): bool
     */
    private function credentialMatches(): Closure
    {
        return static fn (HashedPassword $stored): bool => UserMother::DEFAULT_HASH === $stored->toString();
    }

    /**
     * @return Closure(HashedPassword): bool
     */
    private function credentialDoesNotMatch(): Closure
    {
        return static fn (HashedPassword $stored): bool => self::NEW_HASH === $stored->toString();
    }

    /**
     * @return Closure(): HashedPassword
     */
    private function precomputedHash(): Closure
    {
        return static fn (): HashedPassword => HashedPassword::fromHash(self::NEW_HASH);
    }
}
