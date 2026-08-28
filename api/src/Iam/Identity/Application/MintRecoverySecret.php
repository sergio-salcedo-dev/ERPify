<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidHashedPassword;
use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Issues the identity's recovery secret from its own live session, against a re-proof of the password it
 * currently holds. Any `ACTIVE` identity may mint one — there is no role gate, because the sole administrator
 * this channel exists for is not distinguishable by role from anyone else who could one day be locked out.
 *
 * The credential comparison arrives as a closure the HTTP adapter builds, exactly as in
 * {@see ChangeMyPassword}: hashing and verifying are algorithm knowledge belonging to Infrastructure, so the
 * submitted password is never a value this layer holds, logs or can leak into an exception context. The
 * domain only ever learns "matches" / "does not match".
 *
 * The ordering inside the transaction is the contract:
 *   1. Load the identity under a pessimistic lock. It is the FIRST of the two locks this flow takes, and that
 *      order — user, then secret — is shared with redemption, which is what makes the absence of an ABBA
 *      deadlock between the two demonstrable rather than hoped for.
 *   2. Wall a non-`ACTIVE` identity, in the vocabulary the rest of the product uses for it.
 *   3. Refuse a wrong current password BEFORE the existence of a secret is consulted. The order is the
 *      security property, not a preference: answering 409 to someone who has not re-proved the credential
 *      would turn a stolen session into an oracle over whether a recovery secret exists to go looking for.
 *   4. Only then read the existing row under `SELECT … FOR UPDATE` and refuse a second mint.
 *
 * The aggregate mints its own secret, so the entropy, the TTL and the plaintext assembly are all its
 * business and none of them is restated here. What comes back is the row plus the one plaintext that will
 * ever exist: never persisted, never logged, only its digest on the row. That single moment of legibility is
 * also why the audit projection can record THAT a secret was minted and nothing about WHICH — the selector is
 * the row's key, and a key that appears in the trail is a capability to close the channel in silence.
 *
 * Its object coupling sits two above the default threshold. Two thirds of that number is not collaborators —
 * it is the six outcomes this endpoint declares, which are its published failure vocabulary rather than
 * hidden dependencies, and hiding them behind a coarser exception would trade a readable contract for a
 * metric. The genuine excess is the four lines of credential proof, which are a verbatim twin of
 * {@see ChangeMyPassword}'s: extracting that pair into one collaborator would take this class under the
 * threshold AND delete the duplicate, and it is left undone here only because the second half of it edits a
 * file this change has no other business in. Naming it beats silently carrying two copies of a
 * security-sensitive block.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class MintRecoverySecret
{
    public function __construct(
        private UserRepository $users,
        private RecoverySecretRepository $secrets,
        private RecordRecoverySecretAuditBestEffort $audit,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @param Closure(HashedPassword): bool $verifyCurrent whether the submitted current password is the
     *                                                     stored credential
     *
     * @throws AccountSuspended            when the identity is SUSPENDED (403)
     * @throws AccountDeactivated          when the identity is DEACTIVATED or still INVITED (403)
     * @throws InvalidCurrentPassword      when the submitted current password does not match the stored one (403)
     * @throws RecoverySecretAlreadyExists when the identity already holds one (409)
     * @throws UserNotFound                when the id resolves to no identity (404)
     */
    public function mint(string $userId, Closure $verifyCurrent): GeneratedRecoverySecret
    {
        $now = $this->clock->now();

        $generated = $this->transactionManager->transactional(
            function () use ($userId, $verifyCurrent, $now): GeneratedRecoverySecret {
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);
                $user->ensureActive();

                // A corrupt stored credential cannot be re-proved, and is answered as a wrong one rather than
                // as a 500: the two are indistinguishable to whoever is typing, and the specific failure is
                // for the integrity probe to report, not for this endpoint to disclose.
                try {
                    $current = $user->passwordHash() ?? throw new InvalidCurrentPassword();
                } catch (InvalidHashedPassword) {
                    throw new InvalidCurrentPassword();
                }

                if (!$verifyCurrent($current)) {
                    throw new InvalidCurrentPassword();
                }

                if ($this->secrets->findByUserIdForUpdate($userId) instanceof RecoverySecret) {
                    throw new RecoverySecretAlreadyExists();
                }

                $generated = RecoverySecret::mint($userId, $now);

                $this->secrets->save($generated->secret);
                $this->eventBus->publish(...$generated->secret->pullDomainEvents());

                return $generated;
            },
        );

        // Post-commit, and best-effort: the row is committed and the plaintext in hand is the only copy that
        // will ever be readable, so a failing audit write may not turn a committed mint into a 500 — the
        // owner would be left holding a secret they never saw and unable to mint another, because every
        // later attempt now meets the 409 above.
        $this->audit->recordMinted($userId);

        return $generated;
    }
}
