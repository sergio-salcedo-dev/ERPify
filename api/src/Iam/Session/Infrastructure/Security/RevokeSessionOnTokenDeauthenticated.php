<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\SessionId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\TokenDeauthenticatedEvent;

/**
 * Keeps the registry in step with the firewall's native de-authentication. When a credential change makes
 * `ContextListener::hasUserChanged()` true (`SecurityUser` is `PasswordAuthenticatedUserInterface` and not
 * `EquatableInterface`, so a changed hash de-authenticates), the firewall dispatches
 * {@see TokenDeauthenticatedEvent} and drops the native token. This listener revokes the correlated registry
 * `Session` in the same request, so "my sessions" and the audit trail never diverge from the firewall's real
 * state.
 *
 * It reads only the primitive correlation through the {@see CurrentSessionReference} port and delegates to
 * {@see RevokeSession} (idempotent), so it never couples the firewall to the session store. The trigger
 * (credential change) arrives with the reset/change flows in later stories; this is the coherence mechanism
 * they rely on.
 */
#[AsEventListener(event: TokenDeauthenticatedEvent::class)]
final readonly class RevokeSessionOnTokenDeauthenticated
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private RevokeSession $revokeSession,
    ) {
    }

    public function __invoke(): void
    {
        $sessionId = $this->currentSession->get();

        if ($sessionId instanceof SessionId) {
            $this->revokeSession->revoke($sessionId);
        }
    }
}
