<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use SensitiveParameter;

/**
 * The "forgot my password" use case. Its whole contract is a UNIFORM outcome: whatever the email — unknown,
 * `INVITED`, `SUSPENDED`, `DEACTIVATED` or `ACTIVE` — the caller observes the same thing, because only an
 * `ACTIVE` identity does any work and that work (a token row, an outbox event, a mailed link) is never visible
 * to the anonymous requester. So the response cannot be used to enumerate accounts (SI-12).
 *
 * Only an `ACTIVE` identity mints a {@see SingleUseToken}, supersedes any pending token, persists the digest
 * and records {@see PasswordResetRequested}; every other case returns silently having touched nothing. Every
 * outcome pays the shared {@see PreIdentityTimingFloor} up front, so the silent read path cannot be told apart
 * from the write path by latency either — status, shape and timing all answer uniformly.
 */
final readonly class RequestPasswordReset
{
    /**
     * Reset tokens live shorter than invitations: a recovery link is more sensitive than an onboarding one, so
     * a 1-hour window bounds the exposure of a link sitting in an inbox.
     */
    private const string TOKEN_TTL = '+1 hour';

    public function __construct(
        private UserRepository $users,
        private PasswordResetTokenRepository $tokens,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
        private SendPasswordResetEmailBestEffort $emailSender,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    public function request(#[SensitiveParameter] string $email): void
    {
        // why: without a floor the silent branches (malformed, unknown, non-ACTIVE) answer in the time of one
        // indexed read while the ACTIVE branch pays a multi-write transaction — a latency oracle over account
        // existence. The KDF floor (tens of ms) is paid by every branch and swamps that sub-ms differential.
        $this->timingFloor->equalise();

        $canonicalEmail = Email::tryFrom($email);

        if (!$canonicalEmail instanceof Email) {
            return;
        }

        $user = $this->users->findByEmail($canonicalEmail);

        if (!$user instanceof \Erpify\Iam\Identity\Domain\Entity\User || !$user->isActive()) {
            return;
        }

        $userId = $user->getId();

        if (null === $userId) {
            return;
        }

        $tokenId = Uuid::generate();
        $generated = SingleUseToken::mint($this->clock->now()->modify(self::TOKEN_TTL));
        $token = PasswordResetToken::issue($tokenId, $userId, $generated->token);

        $issued = $this->transactionManager->transactional(function () use ($userId, $token): bool {
            // The user row lock is the supersede mutex: two concurrent forgots would otherwise interleave
            // their delete+insert and leave BOTH tokens live. Serialised on the lock, the loser waits and
            // its own supersede then retires the winner's token — only the latest request stays valid. The
            // uniform read above ran unlocked (outside this transaction a lock guards nothing), so the row
            // is re-acquired here; gone by now means a hard-deleted user — nothing to issue.
            if (!$this->users->findByIdForUpdate($userId) instanceof \Erpify\Iam\Identity\Domain\Entity\User) {
                return false;
            }

            $this->tokens->deleteAllForUser($userId);
            $this->tokens->save($token);

            // The "reset requested" event is recorded by the token aggregate at issue() — publish it from there.
            $this->eventBus->publish(...$token->pullDomainEvents());

            return true;
        });

        // After commit: the reset link's plaintext token (`<id>.<secret>`) is delivered exactly once and never
        // touches the transaction, an event or a log. The send is best-effort — a mailer fault is swallowed so
        // it can't turn the ACTIVE path's uniform 202 into a 500 — so only an ACTIVE identity reaches here and
        // the uniform response never reveals to an anonymous requester whether an email was actually sent.
        if ($issued) {
            $this->emailSender->send($user->email(), $tokenId . '.' . $generated->plaintext());
        }
    }
}
