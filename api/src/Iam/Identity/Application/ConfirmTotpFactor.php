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
 * step in the same transaction that enables it, so the confirming code cannot be replayed at the login's second
 * step inside its own window. A wrong code mutates nothing. The audit row is written inside the transaction after
 * the locking read, so an enabled factor always has its row.
 *
 * Its code budget is spent by the controller, before this runs ({@see
 * \Erpify\Iam\Identity\Infrastructure\Security\SecondFactorCodeThrottle}): a wrong code here feeds no lockout.
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
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws AccountSuspended             when the identity is SUSPENDED (403)
     * @throws AccountDeactivated           when the identity is DEACTIVATED or still INVITED (403)
     * @throws SecondFactorNotEnrolled      when there is no pending enrolment (409)
     * @throws SecondFactorAlreadyEnabled   when the factor is already enabled (409)
     * @throws InvalidSecondFactorCode      when the code matches no fresh step in the window (403)
     * @throws SecondFactorIntegrityFailure when the sealed secret cannot be opened (500)
     * @throws UserNotFound                 when the id resolves to no identity (404)
     */
    public function enableWithCode(string $userId, #[SensitiveParameter] string $code): TotpFactor
    {
        $now = $this->clock->now();

        return $this->transactionManager->transactional(
            function () use ($userId, $code, $now): TotpFactor {
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);
                $user->ensureActive();

                $factor = $this->factors->findByUserIdForUpdate($userId) ?? throw new SecondFactorNotEnrolled();

                if ($factor->isEnabled()) {
                    throw new SecondFactorAlreadyEnabled();
                }

                $step = $this->totp->matchingStep(
                    $this->vault->open($factor),
                    $code,
                    $now,
                    $factor->lastAcceptedStep(),
                );

                if (null === $step) {
                    throw new InvalidSecondFactorCode();
                }

                $factor->enable($step, $now);
                $this->factors->save($factor);
                $this->auditEnabled($userId);

                return $factor;
            },
        );
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
