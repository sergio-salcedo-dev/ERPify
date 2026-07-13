<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\PasswordResetRequested;
use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The "forgot my password" use case. Its whole contract is a UNIFORM outcome: whatever the email — unknown,
 * `INVITED`, `SUSPENDED`, `DEACTIVATED` or `ACTIVE` — the caller observes the same thing, because only an
 * `ACTIVE` identity does any work and that work (a token row, an outbox event, a mailed link) is never visible
 * to the anonymous requester. So the response cannot be used to enumerate accounts (SI-12).
 *
 * Only an `ACTIVE` identity mints a {@see SingleUseToken}, supersedes any pending token, persists the digest
 * and records {@see PasswordResetRequested}; every other case returns silently having touched nothing. The
 * timing differential between the write path and the silent read path is out of scope here (a constant-time
 * floor is a separate cross-cutting concern) — this use case guarantees an identical status and shape.
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
    ) {
    }

    public function request(string $email): void
    {
        try {
            $canonicalEmail = Email::from($email);
        } catch (InvalidEmail) {
            return;
        }

        $user = $this->users->findByEmail($canonicalEmail);

        if (!$user instanceof User || !$user->isActive()) {
            return;
        }

        $userId = $user->getId();

        if (null === $userId) {
            return;
        }

        $generated = SingleUseToken::mint($this->clock->now()->modify(self::TOKEN_TTL));
        $token = PasswordResetToken::issue(Uuid::generate(), $userId, $generated->token);

        $this->transactionManager->transactional(function () use ($userId, $token): void {
            $this->tokens->deleteAllForUser($userId);
            $this->tokens->save($token);

            $this->eventBus->publish(new PasswordResetRequested($userId, occurredOn: $this->clock->now()));
        });

        // The reset email carrying the selector-verifier link `?token=<token id>.<secret>` (the secret is
        // $generated->plaintext(), delivered exactly once and never persisted) is dispatched here. It is
        // deferred to the shared security-email surface built alongside invitation delivery, which this flow
        // reuses; until then the minted token is inert (nobody receives the secret), which is a benign,
        // recoverable state — the endpoint answers the same uniform response regardless.
    }
}
