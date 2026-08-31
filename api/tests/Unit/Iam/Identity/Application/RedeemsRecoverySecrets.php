<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeCurrentSessionBestEffort;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
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

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4001';

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

    /**
     * The correlation the login writes and the compensation reads back. Seeded per case rather than left null,
     * because the compensation's whole point is that it undoes THIS request's session and not the identity's
     * others — a null correlation would make every case pass by revoking nothing.
     */
    private RecordingCurrentSessionReference $currentSession;

    private function initialiseHarness(): void
    {
        $this->signedIn = [];
        $this->sessions = new InMemorySessionRepository();
        $this->auditLogger = new RecordingAuditLogger();
        $this->logger = new RecordingLogger();
        $this->currentSession = new RecordingCurrentSessionReference();
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
            $this->mintSession(UserMother::DEFAULT_ID);
        };
    }

    /**
     * The same seam with the correlation left unwritten — a login that admitted the device while the session
     * registry stashed nothing. It drives the compensation's other reporting branch, which is a real log site
     * and therefore part of what the containment rule has to hold over.
     *
     * @return Closure(string): void
     */
    private function sessionSeamWithoutCorrelation(): Closure
    {
        return function (string $email): void {
            $this->signedIn[] = $email;
        };
    }

    /**
     * What `SessionMintingSuccessListener` does after a successful login: persist the row and stash its id as
     * the request's correlation. The seam models it because the compensation reads that correlation back — a
     * double that only recorded the email would leave every "which session died" claim asserting over a store
     * the login never wrote to.
     */
    private function mintSession(string $userId): SessionId
    {
        $sessionId = SessionId::generate();
        $session = Session::start(
            $sessionId->toString(),
            $userId,
            self::ORGANIZATION_ID,
            'test-device',
            null,
            // Parenthesised deliberately: PDepend cannot parse `new X()->method()`, so the unwrapped
            // PHP 8.4 form fails `make php.md` with a parser error rather than a violation.
            (new DateTimeImmutable(self::NOW))->modify('+7 days'),
        );
        $session->pullDomainEvents();

        $this->sessions->save($session);
        $this->currentSession->set($sessionId);

        return $sessionId;
    }

    /**
     * The ids still admissible for this user. The compensation's whole claim is about WHICH of these survive,
     * so a case asserts the set rather than a call count: a count cannot tell a precise revoke from a coarse
     * one that happened to run once.
     *
     * @return list<string>
     */
    private function activeSessionIds(string $userId): array
    {
        return \array_map(
            static fn (Session $session): string => $session->getId() ?? '',
            $this->sessions->findByUserId($userId),
        );
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
            new RevokeCurrentSessionBestEffort(
                $this->currentSession,
                new RevokeSession(
                    $this->sessions,
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
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
