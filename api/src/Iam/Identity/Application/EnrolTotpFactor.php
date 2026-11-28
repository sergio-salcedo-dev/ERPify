<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Domain\TotpAlgorithm;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Starts the signed-in identity's TOTP enrolment against a re-proof of the password it currently holds, and hands
 * back the shared secret for the one response that will ever carry it. The factor stays PENDING — it gates no
 * login — until {@see ConfirmTotpFactor} accepts a code computed from that secret.
 *
 * The ordering inside the transaction is the contract, the one {@see MintRecoverySecret} follows:
 *   1. Lock the identity row first: user, then factor, then the factor's keystore row is the order every path
 *      that takes them agrees on ({@see TotpFactorRepository}).
 *   2. Wall a non-`ACTIVE` identity.
 *   3. Refuse a wrong current password BEFORE the factor is consulted or locked, the order minting follows: a
 *      refused re-proof takes no lock on the factor and writes nothing, and every answer about the factor goes to
 *      a caller who has proved the password. (The factor's existence is not a secret from the account's own
 *      session: the confirmation route answers it.)
 *   4. Only then read the factor under `FOR UPDATE`: an enabled one is refused (replacing it would remove the
 *      owner's authenticator without the code the owner holds); a pending one is restarted under its own scope;
 *      none is created with a fresh random scope.
 *
 * The secret is drawn by the algorithm port, sealed by {@see TotpSecretVault} and never held by the aggregate in
 * clear. The audit row is written inside the transaction, after the locking read of the identity: it records
 * THAT an enrolment started and nothing about the secret, and a failed write rolls the enrolment back before any
 * secret has been shown, so the owner simply retries.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the five refusals it declares are its published failure
 *                                                   vocabulary; the rest are the ports of one atomic act
 */
final readonly class EnrolTotpFactor
{
    private const string ENROLMENT_STARTED_ACTION = 'SECOND_FACTOR_ENROLMENT_STARTED';

    public function __construct(
        private UserRepository $users,
        private TotpFactorRepository $factors,
        private ProveCurrentPassword $proveCurrentPassword,
        private TotpAlgorithm $totp,
        private TotpSecretVault $vault,
        private AuditLogger $auditLogger,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param Closure(HashedPassword): bool $verifyCurrent whether the submitted current password is the
     *                                                     stored credential
     *
     * @throws AccountSuspended           when the identity is SUSPENDED (403)
     * @throws AccountDeactivated         when the identity is DEACTIVATED or still INVITED (403)
     * @throws InvalidCurrentPassword     when the submitted current password does not match the stored one (403)
     * @throws SecondFactorAlreadyEnabled when the identity's factor is already enabled (409)
     * @throws UserNotFound               when the id resolves to no identity (404)
     */
    public function enrol(string $userId, Closure $verifyCurrent): TotpEnrolment
    {
        return $this->transactionManager->transactional(
            function () use ($userId, $verifyCurrent): TotpEnrolment {
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);
                $user->ensureActive();

                $this->proveCurrentPassword->ensure($user, $verifyCurrent);

                $factor = $this->factors->findByUserIdForUpdate($userId);

                if ($factor instanceof TotpFactor && $factor->isEnabled()) {
                    throw new SecondFactorAlreadyEnabled();
                }

                $secret = $this->totp->newSecret();
                $scopeId = $factor instanceof TotpFactor ? $factor->scopeId() : Uuid::generate();
                $sealed = $this->vault->seal($scopeId, $userId, $secret);

                $factor?->restartEnrolment($sealed);
                $factor ??= TotpFactor::enrol(Uuid::generate(), $userId, $scopeId, $sealed);

                $this->factors->save($factor);
                $this->auditEnrolmentStarted($userId);

                return new TotpEnrolment(
                    $this->totp->encodedSecret($secret),
                    $this->totp->provisioningUri($secret, $user->email()),
                );
            },
        );
    }

    private function auditEnrolmentStarted(string $userId): void
    {
        $this->auditLogger->log(
            self::ENROLMENT_STARTED_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
        );
    }
}
