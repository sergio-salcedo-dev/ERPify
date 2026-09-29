<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use DateInterval;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\DomainEvent;
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
 * A re-login from the same browser keeps the native session's attributes (the firewall migrates the session
 * rather than clearing it), so the bag may still correlate an earlier session. Overwriting that correlation
 * would leave the earlier row `ACTIVE` and reachable by nothing — a phantom device in "my sessions" until its
 * expiry, and a row the retention sweep removes only once that expiry is 90 days behind it (~97 days after the
 * login). So the session the bag correlated, if it is still admissible, is revoked in the SAME
 * transaction that persists the new one, and its {@see \Erpify\Iam\Session\Domain\Event\SessionRevoked} is
 * published beside the new session's start: both land or neither does. It is revoked whichever identity owns
 * it — that row was reachable only through this cookie, whose holder could already revoke it as the current
 * session, so no capability is granted. Only that one row is touched; this is not a "log out everywhere", and
 * revoking a single row by id takes no set lock, so it cannot close a lock cycle with a bulk revocation.
 *
 * That row is read under its row lock ({@see SessionRepository::lockActiveById()}), because the read decides
 * whether a `SessionRevoked` is published. Read unlocked, a rival that revokes or deletes the row between this
 * read and the flush — a "log out everywhere", a credential change's teardown, a single-session log-out, an
 * erasure — leaves the flush re-stamping `revoked_at` over a revocation that already happened and publishing a
 * `SessionRevoked` for it: a duplicate when the rival revoked that row alone, and after an erasure an event naming
 * the person that outlives the erasure. Locked, the rival either waits
 * for this transaction or has committed first, and then the row no longer reads as admissible and nothing is
 * published for it.
 *
 * Two residuals remain, both bounded the same way — an unreachable `ACTIVE` row the sweep removes once its expiry
 * is 90 days behind it, ~97 days after the login. A post-commit failure to write the new correlation leaves the
 * NEW row unreachable. A mint the store refuses rolls the revocation back with it, while the minting listener
 * drops the cookie, so the EARLIER row is then left `ACTIVE` and equally unreachable.
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

        $previousId = $this->currentSession->get();

        $this->transactionManager->transactional(function () use ($session, $previousId): void {
            $revoked = $this->revokePrevious($previousId);

            $this->sessions->save($session);
            $this->eventBus->publish(...$revoked, ...$session->pullDomainEvents());
        });

        $this->currentSession->set($sessionId);

        return $sessionId;
    }

    /**
     * Revokes and saves the session the bag correlated before this login, returning the events it recorded so
     * they are published with the new session's; nothing when there was no correlation or the row is no longer
     * admissible once its lock is held.
     *
     * @return list<DomainEvent>
     */
    private function revokePrevious(?SessionId $previousId): array
    {
        if (!$previousId instanceof SessionId) {
            return [];
        }

        $previous = $this->sessions->lockActiveById($previousId);

        if (!$previous instanceof Session) {
            return [];
        }

        $previous->revoke();
        $this->sessions->save($previous);

        return $previous->pullDomainEvents();
    }
}
