<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Stores the configured hasher's encoding of a credential a login has just verified, so a hash minted under
 * other parameters (a bcrypt cost other than the configured one, an algorithm `auto` no longer mints) follows
 * the configuration the first time its owner proves it. Deciding THAT a hash needs re-encoding, and computing
 * the new one, is the hasher's job and happens in Infrastructure; this layer receives both hashes as opaque
 * values and persists the swap.
 *
 * **Only over the exact credential the login verified**, decided by the store
 * ({@see UserRepository::replacePasswordHashIfUnchanged()}). A reset or a self-service change committing
 * between the verification and this write is therefore never overwritten by an encoding of the secret it
 * superseded — and, because a refusal hydrates nothing, the aggregate the session will carry keeps the hash
 * the login actually proved, so the next request compares it against the replaced row and signs the session
 * out, which is the de-authentication both replacement flows rely on. The price is paid by the benign race:
 * the loser of two simultaneous first logins on one legacy hash is refused too, and signed out once on its
 * next request, because a refusal cannot tell a re-encoding of the same secret from a new one.
 *
 * **Best-effort, because the login it rides has already succeeded.** An upgrade that cannot be stored costs
 * nothing but the upgrade — the old hash still verifies the same secret — so every failure is swallowed and
 * reported, never raised into a login that would otherwise answer 200. A failed statement rolls back, and the
 * aggregate was never touched, so the session and the row still agree. The report carries the failure's CLASS
 * and not the throwable: the statement that failed carried both hashes as parameters, and a driver message is
 * not a sink this code controls. It goes to the always-on `observability` channel (bound in `services.yaml`),
 * because a login is a 2xx and on the default channel prod would discard the line.
 *
 * No domain event, no audit row, no mail, no session revoke and no lockout change: nothing about the secret
 * moved, so none of the facts those effects announce is true.
 */
final readonly class RehashPasswordBestEffort
{
    public function __construct(
        private UserRepository $users,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Both hashes arrive as the strings the hasher produced and become value objects inside the guarded block,
     * so even a refusal of the value object is one more failure this swallows rather than a 500 on a login.
     */
    public function rehash(
        string $userId,
        #[SensitiveParameter]
        string $verifiedHash,
        #[SensitiveParameter]
        string $rehashedHash,
    ): void {
        try {
            $verified = HashedPassword::fromHash($verifiedHash);
            $rehashed = HashedPassword::fromHash($rehashedHash);

            $this->transactionManager->transactional(
                fn (): bool => $this->users->replacePasswordHashIfUnchanged($userId, $verified, $rehashed),
            );
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Login succeeded; password rehash at the configured cost skipped (rehash failed).',
                ['exception_class' => $throwable::class],
            );
        }
    }
}
