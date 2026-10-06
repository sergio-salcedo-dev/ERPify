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
 * **A login over a native session that already correlates a live registry row revokes that row in the same
 * transaction.** The correlation is about to be overwritten, and once it is, no request can ever present the
 * replaced row again — so leaving it `ACTIVE` would list a device nobody holds on its owner's "my sessions"
 * until it expired, and keep its `ip`/`device` until the retention sweep after that. The replaced row is read through
 * {@see SessionRepository::findActiveById()}, so one already revoked or past its expiry is left alone, and it
 * is retired through {@see Session::revoke()}, publishing the same
 * {@see \Erpify\Iam\Session\Domain\Event\SessionRevoked} a single "log out this device" does.
 *
 * **Only the signing-in identity's own row is revoked.** Two people sharing a browser leave the first one's row
 * live when the second signs in over it: the bag no longer names it, so that browser cannot present it again,
 * but its owner still sees it among their sessions and can retire it there, and it expires on its own. Revoking
 * it would publish a {@see \Erpify\Iam\Session\Domain\Event\SessionRevoked} naming a person from a request
 * that person did not make and whose erasure it does not serialise against — an event that, racing that erasure,
 * reaches the event store after its anonymising pass and keeps the real id until the erasure re-sweep reaches it
 * (`docs/adr/audit-activity-log.md` D4.2). A writer about a person here is
 * always a request of that person's.
 *
 * The correlation is written through {@see CurrentSessionReference} only AFTER the transaction commits, so a
 * failed persist never leaves an `iamSessionId` pointing at a session that does not exist — and never leaves
 * the replaced row revoked while the bag still names it, since both writes roll back together. The minting
 * caller treats any throw as fail-closed (invalidate the native session + 503).
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
        $replaced = $this->currentSession->get();

        $sessionId = SessionId::generate();
        $expiresAt = $this->clock->now()->add(new DateInterval(self::TTL_SPEC));
        $session = Session::start($sessionId->toString(), $userId, $organizationId, $device, $ip, $expiresAt);

        $this->transactionManager->transactional(function () use ($replaced, $session, $userId): void {
            if ($replaced instanceof SessionId) {
                $this->revokeReplaced($replaced, $userId);
            }

            $this->sessions->save($session);
            $this->eventBus->publish(...$session->pullDomainEvents());
        });

        $this->currentSession->set($sessionId);

        return $sessionId;
    }

    private function revokeReplaced(SessionId $replaced, string $userId): void
    {
        $previous = $this->sessions->findActiveById($replaced);

        if (!$previous instanceof Session || 0 !== \strcasecmp($previous->userId(), $userId)) {
            return;
        }

        $previous->revoke();
        $this->sessions->save($previous);
        $this->eventBus->publish(...$previous->pullDomainEvents());
    }
}
