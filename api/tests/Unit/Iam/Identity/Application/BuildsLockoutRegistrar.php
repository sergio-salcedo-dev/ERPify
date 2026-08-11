<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Psr\Log\NullLogger;

/**
 * Assembles a {@see LoginAttemptRegistrar} around in-memory fakes, mirroring {@see BuildsFailureHandler}.
 *
 * The assembly lives in a trait so each test class is coupled to what it *asserts* rather than to everything
 * the collaborator graph needs to exist — the registrar takes five collaborators, and counting them against
 * every test class that merely wants one built is what pushed both past the object-coupling threshold.
 *
 * @internal
 */
trait BuildsLockoutRegistrar
{
    private const string REGISTRAR_NOW = '2026-07-11T12:00:00+00:00';

    private function registrarWith(
        InMemoryUserRepository $repository,
        RecordingEventBus $eventBus,
        TransactionManager $transactionManager,
        ?AuditLogger $auditLogger = null,
    ): LoginAttemptRegistrar {
        return new LoginAttemptRegistrar(
            $repository,
            $eventBus,
            $transactionManager,
            new FixedClock(new DateTimeImmutable(self::REGISTRAR_NOW)),
            new RecordLockoutAuditBestEffort($auditLogger ?? new RecordingAuditLogger(), new NullLogger()),
        );
    }

    /**
     * The instant every assembled registrar is frozen at, so a test can weigh a lock against the same clock
     * the registrar used without importing the type itself.
     */
    private static function lockoutProbeInstant(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::REGISTRAR_NOW);
    }

    /**
     * An identity one failed attempt short of its lockout, so a single `recordFailure()` trips it.
     */
    private function userOneAttemptFromLockout(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::REGISTRAR_NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS - 1; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        return $user;
    }
}
