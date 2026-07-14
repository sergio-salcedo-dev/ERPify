<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Exception\InvalidToken;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use SensitiveParameter;

/**
 * The heart of the invitation flow: consumes a `<invitationId>.<secret>` token and, in ONE transaction, sets
 * the password, admits the identity (`INVITED → ACTIVE`) and retires the invitation (`SENT → ACCEPTED`).
 * Retiring the invitation in the same transaction as the activation is what makes the token single-use — the
 * `ACCEPTED` status IS the consumption. The invitation is loaded under a pessimistic write lock so two
 * concurrent accepts of the same token serialise on the row: the loser blocks, re-reads the retired status
 * and is rejected, never minting a second session nor overwriting the credential. Every dead-token case
 * (malformed, non-existent, wrong secret, expired, or already-consumed/revoked) raises the SAME opaque
 * {@see InvalidToken} BEFORE any mutation, so they are byte-identical and the reason is never revealed.
 *
 * Session establishment (programmatic login + anti-fixation regeneration) is the caller's job, run AFTER this
 * transaction commits — it lives in the HTTP adapter because it is a framework concern; this use case ends the
 * moment the domain state is durable.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @param Closure(): HashedPassword $hashPassword defers the KDF of the submitted password; invoked only
     *                                                after every dead-token check has passed, so a garbage
     *                                                token never costs a KDF run. The hash therefore runs
     *                                                while the invitation row lock is held — a few extra ms
     *                                                serialising only concurrent accepts of the SAME token,
     *                                                which is the very race the lock exists to serialise.
     *
     * @throws InvalidToken when the token is not eligible in any of the five cases
     */
    public function accept(
        #[SensitiveParameter]
        string $presentedToken,
        Closure $hashPassword,
    ): AcceptedInvitation {
        return $this->transactionManager->transactional(
            function () use ($presentedToken, $hashPassword): AcceptedInvitation {
                [$invitationId, $secret] = $this->splitToken($presentedToken);

                $invitation = $this->invitations->findByIdForUpdate($invitationId) ?? throw new InvalidToken();

                if (!$invitation->verify($secret, $this->clock->now())) {
                    throw new InvalidToken();
                }

                if (InvitationStatus::SENT !== $invitation->status()) {
                    throw new InvalidToken();
                }

                $user = $this->users->findById($invitation->invitedUserId()) ?? throw new InvalidToken();

                // A SENT invitation must point at an INVITED identity; any other status is a desync that would
                // otherwise leak a distinguishable 409 from activate(), so it collapses to the opaque wall.
                if (IdentityStatus::INVITED !== $user->status()) {
                    throw new InvalidToken();
                }

                $user->activate($hashPassword());

                $invitation->accept();

                $this->users->save($user);
                $this->invitations->save($invitation);
                $this->eventBus->publish(...$user->pullDomainEvents(), ...$invitation->pullDomainEvents());

                return new AcceptedInvitation($invitation->invitedUserId(), $user->email());
            },
        );
    }

    /**
     * Splits the `<invitationId>.<secret>` selector-verifier. Any malformed shape — no separator, empty half,
     * or a non-UUID selector — is the same opaque {@see InvalidToken}, so a tampered token never leaks a
     * distinct error. The selector is validated as a UUID here rather than letting the repository lookup throw
     * a different `invalid-uuid` type that would break the five-case opacity.
     *
     * @return array{0: string, 1: string}
     */
    private function splitToken(#[SensitiveParameter] string $presentedToken): array
    {
        $separator = \strpos($presentedToken, '.');

        if (false === $separator || 0 === $separator) {
            throw new InvalidToken();
        }

        $invitationId = \substr($presentedToken, 0, $separator);
        $secret = \substr($presentedToken, $separator + 1);

        if ('' === $secret || !Uuid::isValid($invitationId)) {
            throw new InvalidToken();
        }

        return [$invitationId, $secret];
    }
}
