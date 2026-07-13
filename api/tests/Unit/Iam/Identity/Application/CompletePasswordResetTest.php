<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\CompletePasswordReset;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidResetToken;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Token\Domain\SingleUseToken;
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
#[CoversClass(CompletePasswordReset::class)]
final class CompletePasswordResetTest extends TestCase
{
    private const string NOW = '2026-07-13T12:00:00+00:00';

    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01';

    public function testResetsClearsTheLockConsumesTheTokenRevokesAllAndEmits(): void
    {
        $user = UserMother::create();
        $this->lock($user);
        $this->assertTrue($user->isLockedAt($this->now()));

        [$token, $secret] = $this->mintToken(UserMother::DEFAULT_ID);
        $tokens = new InMemoryPasswordResetTokenRepository($token);
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();
        $newHash = HashedPassword::fromHash('new-argon2id-hash');

        $this->useCase(new InMemoryUserRepository($user), $tokens, $sessions, $eventBus)
            ->complete(self::TOKEN_ID . '.' . $secret, $newHash)
        ;

        $this->assertTrue($user->passwordHash()?->equals($newHash));
        $this->assertFalse($user->isLockedAt($this->now()));
        $this->assertSame([$token], $tokens->consumed);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame('erpify.iam.identity.password-reset-completed', $eventBus->publishedEvents[0]::eventName());
    }

    public function testMalformedTokenIsRejectedWithoutMutating(): void
    {
        $this->assertRejectedWithoutMutating(InvalidResetToken::class, 'no-selector-verifier-shape');
    }

    public function testUnknownTokenIsRejectedWithoutMutating(): void
    {
        // A well-formed selector for a row that does not exist (an unknown id, or one already consumed).
        $this->assertRejectedWithoutMutating(
            InvalidResetToken::class,
            '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5eff.some-secret',
        );
    }

    public function testExpiredTokenIsRejectedWithoutMutating(): void
    {
        [$token, $secret] = $this->mintToken(UserMother::DEFAULT_ID, '-1 hour');

        $this->assertRejectedWithoutMutating(
            InvalidResetToken::class,
            self::TOKEN_ID . '.' . $secret,
            UserMother::create(),
            $token,
        );
    }

    public function testSuspendedIdentityIsWalledWithoutConsumingTheToken(): void
    {
        $user = UserMother::create();
        $user->suspend();
        $user->pullDomainEvents();
        [$token, $secret] = $this->mintToken(UserMother::DEFAULT_ID);

        $this->assertRejectedWithoutMutating(AccountSuspended::class, self::TOKEN_ID . '.' . $secret, $user, $token);
    }

    public function testDeactivatedIdentityIsWalledWithoutConsumingTheToken(): void
    {
        $user = UserMother::create();
        $user->deactivate();
        $user->pullDomainEvents();
        [$token, $secret] = $this->mintToken(UserMother::DEFAULT_ID);

        $this->assertRejectedWithoutMutating(AccountDeactivated::class, self::TOKEN_ID . '.' . $secret, $user, $token);
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertRejectedWithoutMutating(
        string $expected,
        string $token,
        ?User $user = null,
        ?PasswordResetToken $preset = null,
    ): void {
        $users = new InMemoryUserRepository($user ?? UserMother::create());
        $tokens = $preset instanceof PasswordResetToken
            ? new InMemoryPasswordResetTokenRepository($preset)
            : new InMemoryPasswordResetTokenRepository();
        $sessions = new InMemorySessionRepository();
        $eventBus = new RecordingEventBus();

        try {
            $this->useCase($users, $tokens, $sessions, $eventBus)
                ->complete($token, HashedPassword::fromHash('new-argon2id-hash'))
            ;
            $this->fail('Expected ' . $expected);
        } catch (Throwable $throwable) {
            $this->assertInstanceOf($expected, $throwable);
        }

        $this->assertSame([], $tokens->consumed);
        $this->assertSame([], $users->saved);
        $this->assertSame([], $sessions->revokeAllCalls);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        InMemorySessionRepository $sessions,
        RecordingEventBus $eventBus,
    ): CompletePasswordReset {
        $clock = new FixedClock($this->now());

        return new CompletePasswordReset(
            $users,
            $tokens,
            new RevokeSessionsBestEffort(
                new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
                new NullLogger(),
            ),
            $eventBus,
            new InlineTransactionManager(),
            $clock,
        );
    }

    /**
     * @return array{PasswordResetToken, string}
     */
    private function mintToken(string $userId, string $expiry = '+1 hour'): array
    {
        $generated = SingleUseToken::mint($this->now()->modify($expiry));

        return [PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token), $generated->plaintext()];
    }

    private function lock(User $user): void
    {
        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($this->now());
        }

        $user->pullDomainEvents();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
