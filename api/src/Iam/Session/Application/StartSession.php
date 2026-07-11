<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Mints the server-side session for an already-admitted identity: the server generates the {@see SessionId} (v7)
 * and the absolute expiry from the injected {@see Clock} (never a client clock), the aggregate records
 * {@see \Erpify\Iam\Session\Domain\Event\SessionStarted}, and persist + publish commit in one transaction — the
 * aggregate row, its event-store rows and the outbox land atomically (or the login fails closed).
 *
 * The correlation is written through {@see CurrentSessionReference} only AFTER the transaction commits, so a
 * failed persist never leaves an `iamSessionId` pointing at a session that does not exist. The minting caller
 * treats any throw as fail-closed (invalidate the native session + 503).
 *
 * The TTL is an absolute cap: there is no idle-timeout enforcement, so this ceiling is the sole bound on a
 * session's lifetime.
 */
final readonly class StartSession
{
    private const string TTL_SPEC = 'P7D';

    public function __construct(
        private SessionRepository $sessions,
        private CurrentSessionReference $currentSession,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    public function start(string $userId, string $organizationId, string $device, ?string $ip): SessionId
    {
        $sessionId = SessionId::generate();
        $expiresAt = $this->clock->now()->add(new DateInterval(self::TTL_SPEC));
        $session = Session::start($sessionId->toString(), $userId, $organizationId, $device, $ip, $expiresAt);

        $this->transactionManager->transactional(function () use ($session): void {
            $this->sessions->save($session);
            $this->eventBus->publish(...$session->pullDomainEvents());
        });

        $this->currentSession->set($sessionId);

        return $sessionId;
    }
}
