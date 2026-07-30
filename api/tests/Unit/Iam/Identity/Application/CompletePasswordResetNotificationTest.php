<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\CompletePasswordReset;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Where the password-changed notification sits in {@see CompletePasswordReset}, which is three separate
 * properties and no one of them implies another: it happens after the commit, it happens after the session
 * revocation, and its failure does not surface. Asserting merely that a mail was sent holds equally when the
 * send runs inside the write transaction or ahead of the revoke, so each is pinned at the moment of the send
 * rather than after the use case returns.
 *
 * Separate from {@see CompletePasswordResetTest} because it is a distinct contract with its own fixture
 * shape, not because that class grew long.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CompletePasswordReset::class)]
final class CompletePasswordResetNotificationTest extends TestCase
{
    private const string NOW = '2026-07-13T12:00:00+00:00';

    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01';

    public function testTheNotificationIsSentOnlyOnceTheResetHasCommitted(): void
    {
        // Sending from inside the transaction would announce a credential change a rollback could still undo,
        // and would hold the user row lock across a blocking SMTP round trip.
        $transactions = new InlineTransactionManager();
        $committedAtSend = null;
        $emails = new RecordingPasswordChangedEmailSender(observe: static function () use (
            $transactions,
            &$committedAtSend,
        ): void {
            $committedAtSend = $transactions->committed;
        });

        $this->complete($emails, transactions: $transactions);

        $this->assertTrue($committedAtSend, 'The password-changed notification was sent inside the transaction.');
        $this->assertSame([UserMother::DEFAULT_EMAIL], $emails->sentTo);
    }

    public function testTheNotificationIsSentOnlyOnceEverySessionIsRevoked(): void
    {
        // Revocation is the containment this flow exists to perform, and the send blocks (Symfony's
        // SendEmailMessage is deliberately unrouted). Ahead of the revoke, a hung mail server would keep the
        // sessions of a just-compromised account alive for the whole SMTP timeout.
        $sessions = new InMemorySessionRepository();
        $revokedAtSend = null;
        $emails = new RecordingPasswordChangedEmailSender(observe: static function () use (
            $sessions,
            &$revokedAtSend,
        ): void {
            $revokedAtSend = $sessions->revokeAllCalls;
        });

        $this->complete($emails, sessions: $sessions);

        $this->assertSame(
            [UserMother::DEFAULT_ID],
            $revokedAtSend,
            'The password-changed notification was sent before the sessions were revoked.',
        );
    }

    public function testAFailedNotificationDoesNotStrandACommittedReset(): void
    {
        // Best-effort: the credential change has already committed, so surfacing a mailer fault would answer
        // a successful reset with a 500 and tell the caller their password never changed.
        $user = UserMother::create();
        $sessions = new InMemorySessionRepository();
        [$tokens, $secret] = $this->mintToken();
        $newHash = HashedPassword::fromHash('new-argon2id-hash');

        $email = $this->useCase(
            new InMemoryUserRepository($user),
            $tokens,
            $sessions,
            new RecordingPasswordChangedEmailSender(new RuntimeException('smtp is down')),
            new InlineTransactionManager(),
        )->complete(self::TOKEN_ID . '.' . $secret, static fn (): HashedPassword => $newHash);

        $this->assertSame(UserMother::DEFAULT_EMAIL, $email);
        $this->assertTrue($user->passwordHash()?->equals($newHash));
        $this->assertCount(1, $tokens->consumed);
        $this->assertSame([UserMother::DEFAULT_ID], $sessions->revokeAllCalls);
    }

    private function complete(
        RecordingPasswordChangedEmailSender $emails,
        ?InlineTransactionManager $transactions = null,
        ?InMemorySessionRepository $sessions = null,
    ): void {
        [$tokens, $secret] = $this->mintToken();

        $this->useCase(
            new InMemoryUserRepository(UserMother::create()),
            $tokens,
            $sessions ?? new InMemorySessionRepository(),
            $emails,
            $transactions ?? new InlineTransactionManager(),
        )->complete(
            self::TOKEN_ID . '.' . $secret,
            static fn (): HashedPassword => HashedPassword::fromHash('new-argon2id-hash'),
        );
    }

    /**
     * @return array{InMemoryPasswordResetTokenRepository, string}
     */
    private function mintToken(): array
    {
        $generated = SingleUseToken::mint($this->now()->modify('+1 hour'));

        return [
            new InMemoryPasswordResetTokenRepository(
                PasswordResetToken::issue(self::TOKEN_ID, UserMother::DEFAULT_ID, $generated->token),
            ),
            $generated->plaintext(),
        ];
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        InMemorySessionRepository $sessions,
        RecordingPasswordChangedEmailSender $emails,
        InlineTransactionManager $transactions,
    ): CompletePasswordReset {
        $clock = new FixedClock($this->now());

        return new CompletePasswordReset(
            $users,
            $tokens,
            new RevokeSessionsBestEffort(
                new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
                new NullLogger(),
            ),
            new SendPasswordChangedEmailBestEffort($emails, new NullLogger()),
            new RecordingEventBus(),
            $transactions,
            $clock,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
