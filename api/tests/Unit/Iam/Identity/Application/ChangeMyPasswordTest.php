<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeMyPassword;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\Exception\NewPasswordMustDiffer;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Weaves the real {@see RevokeAllSessions} graph (with the Session context's in-memory store) rather than a
 * mock, since it is a `final` published seam — hence the coupling this contract legitimately carries.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ChangeMyPassword::class)]
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
     * An identity with no credential cannot re-prove one, so it takes the same refusal as a wrong password
     * rather than reaching the aggregate — which would answer with a lifecycle transition error and tell a
     * caller which of the two walls it hit.
     */
    public function testACredentiallessIdentityIsRefusedAsAWrongCurrentPassword(): void
    {
        $this->assertRefusedWithoutEffect(
            InvalidCurrentPassword::class,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            UserMother::invited(),
        );
    }

    /**
     * The lifecycle wall is the aggregate's, so it lands after the credential has already been re-proved and
     * the new one hashed — one KDF run, unlike the two refusals above. That asymmetry is deliberate rather than
     * an oversight: a walled identity holds no session (the status change revokes them), so nothing can reach
     * this arm from outside, and buying it a cheaper refusal would mean asking the aggregate for its status
     * before proving the caller is its owner.
     */
    public function testANonActiveIdentityIsWalledByTheAggregateWithoutAnyEffect(): void
    {
        $suspended = UserMother::create();
        $suspended->suspend();
        $suspended->pullDomainEvents();

        $this->assertRefusedWithoutEffect(
            InvalidIdentityTransition::class,
            $this->credentialMatches(),
            $this->credentialDoesNotMatch(),
            $suspended,
            expectedKdfRuns: 1,
        );
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
        $clock = new FixedClock(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));

        return new ChangeMyPassword(
            $users ?? new InMemoryUserRepository(UserMother::create()),
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
