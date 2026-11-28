<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidSecondFactorCode;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorNotEnrolled;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Domain\TotpAlgorithm;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Enables the signed-in identity's pending TOTP factor once its owner presents a code computed from the secret
 * the enrolment showed them — the proof that the authenticator really holds it, without which an enabled factor
 * could lock its own owner out.
 *
 * Under the identity's row lock and then the factor's: the step a code matched becomes the factor's last accepted
 * step in the same transaction that enables it, so the confirming code cannot be replayed inside its own window. The
 * audit row is written inside the transaction after the locking read, so an enabled factor always has its row.
 *
 * **A wrong code is counted, and the count commits although the request is refused.** The factor caps a pending
 * enrolment at {@see TotpFactor::MAX_FAILED_CONFIRMATIONS} wrong codes; the transaction records the failure and
 * returns, and the refusal is thrown only after the commit, because a throw inside the transaction would roll the
 * count back with it and the cap would never be reached. A pending enrolment that expired or ran out of codes
 * answers like no enrolment at all, since the remedy is the same: start one again, behind the password.
 *
 * **An activation is treated like a password change, by the owner's decision.** After the commit every session of
 * the identity is revoked ({@see RevokeSessionsBestEffort}) and the owner is mailed
 * ({@see SendSecondFactorEnabledEmailBestEffort}), in that order, both best effort; the controller then signs the
 * device that asked back in, so it is the only one left signed in. A session an attacker held alongside the owner
 * does not survive the moment the account tightens, and an activation the owner did not make is announced to them.
 *
 * The controller spends the code budget before this runs ({@see
 * \Erpify\Iam\Identity\Infrastructure\Security\SecondFactorCodeThrottle}), an attenuator in front of the cap
 * this counts. A wrong code here feeds no lockout.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the seven outcomes it declares are its published failure
 *                                                   vocabulary; the rest are the ports of one atomic act
 */
final readonly class ConfirmTotpFactor
{
    private const string ENABLED_ACTION = 'SECOND_FACTOR_ENABLED';

    public function __construct(
        private UserRepository $users,
        private TotpFactorRepository $factors,
        private TotpAlgorithm $totp,
        private TotpSecretVault $vault,
        private AuditLogger $auditLogger,
        private RevokeSessionsBestEffort $revokeSessions,
        private SendSecondFactorEnabledEmailBestEffort $notifySecondFactorEnabled,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws AccountSuspended             when the identity is SUSPENDED (403)
     * @throws AccountDeactivated           when the identity is DEACTIVATED or still INVITED (403)
     * @throws SecondFactorNotEnrolled      when there is no pending enrolment, or it expired or ran out of codes (409)
     * @throws SecondFactorAlreadyEnabled   when the factor is already enabled (409)
     * @throws InvalidSecondFactorCode      when the code matches no fresh step in the window (403)
     * @throws SecondFactorIntegrityFailure when the sealed secret cannot be opened (500)
     * @throws UserNotFound                 when the id resolves to no identity (404)
     */
    public function enableWithCode(string $userId, #[SensitiveParameter] string $code): TotpFactor
    {
        $now = $this->clock->now();

        $ownerEmail = '';

        // Null when the code was wrong: the failure is counted and committed, and the refusal follows the commit.
        $factor = $this->transactionManager->transactional(
            function () use ($userId, $code, $now, &$ownerEmail): ?TotpFactor {
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);
                $user->ensureActive();

                $factor = $this->factors->findByUserIdForUpdate($userId) ?? throw new SecondFactorNotEnrolled();

                if ($factor->isEnabled()) {
                    throw new SecondFactorAlreadyEnabled();
                }

                if ($factor->pendingEnrolmentClosedAt($now)) {
                    throw new SecondFactorNotEnrolled();
                }

                $step = $this->totp->matchingStep(
                    $this->vault->open($factor),
                    $code,
                    $now,
                    $factor->lastAcceptedStep(),
                );

                if (null === $step) {
                    $factor->recordFailedConfirmation($now);
                    $this->factors->save($factor);

                    return null;
                }

                $factor->enable($step, $now);
                $this->factors->save($factor);
                $this->auditEnabled($userId);
                $ownerEmail = $user->email();

                return $factor;
            },
        );

        if (!$factor instanceof TotpFactor) {
            throw new InvalidSecondFactorCode();
        }

        $this->revokeSessions->revoke($userId);
        $this->notifySecondFactorEnabled->send($ownerEmail);

        return $factor;
    }

    private function auditEnabled(string $userId): void
    {
        $this->auditLogger->log(
            self::ENABLED_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
        );
    }
}
