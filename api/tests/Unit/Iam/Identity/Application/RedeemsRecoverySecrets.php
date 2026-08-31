<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;

/**
 * The arrange every redemption case needs, shared by the two classes that make claims about this use case:
 * one about the ORDER it takes its locks in and what each concurrency outcome leaves behind, the other about
 * which identities it admits and what a refusal is allowed to leak.
 *
 * They are separate classes because they are separate subjects, and the harness is a trait rather than a base
 * class because that is the whole of what they share — inheritance here would put a `setUp` and a fixture
 * hierarchy between a case and the thing it asserts.
 */
trait RedeemsRecoverySecrets
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    /**
     * Whatever the use case handed the session seam, in order. Every case reads it — the ones that assert
     * a session WAS established, and the ones whose point is that it was not.
     *
     * @var list<string>
     */
    private array $signedIn = [];

    /**
     * The session store the compensating revoke reaches. A walled identity must not walk away with the
     * session the login already committed, so the cases assert on what this recorded.
     */
    private InMemorySessionRepository $sessions;

    /**
     * The trail the three transitions and the compensation project onto. A compensated redemption persists
     * no consumption and publishes no event, so this row is the only durable trace it leaves.
     */
    private RecordingAuditLogger $auditLogger;

    /**
     * The PSR-3 sink both best-effort collaborators write to when they swallow a failure. It is the fourth
     * containment axis: the event payload, the audit row and the DTO each have a shape a test can inspect,
     * while a log line is a free-form message plus an arbitrary context array.
     */
    private RecordingLogger $logger;

    private function initialiseHarness(): void
    {
        $this->signedIn = [];
        $this->sessions = new InMemorySessionRepository();
        $this->auditLogger = new RecordingAuditLogger();
        $this->logger = new RecordingLogger();
    }

    /**
     * @return list<string>
     */
    private function auditedActions(): array
    {
        return \array_column($this->auditLogger->records, 'action');
    }

    /**
     * The seam the HTTP adapter fills with `Security::login()`. It records rather than ignores, so a case
     * whose point is that no session was minted can assert that instead of trusting the absence of a
     * side effect it never observed.
     *
     * @return Closure(string): void
     */
    private function sessionSeam(): Closure
    {
        return function (string $email): void {
            $this->signedIn[] = $email;
        };
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryRecoverySecretRepository $secrets,
        ?RecordingEventBus $eventBus = null,
    ): RedeemRecoverySecret {
        return new RedeemRecoverySecret(
            $users,
            $secrets,
            new RecordRecoverySecretAuditBestEffort($this->auditLogger, $this->logger),
            new RevokeSessionsBestEffort(
                new RevokeAllSessions(
                    $this->sessions,
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
                    FixedClock::at(self::NOW),
                ),
                $this->logger,
            ),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );
    }

    private function mintFor(
        InMemoryRecoverySecretRepository $secrets,
        string $userId,
    ): GeneratedRecoverySecret {
        $generated = RecoverySecret::mint($userId, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        $secrets->save($generated->secret);

        return $generated;
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }
}
