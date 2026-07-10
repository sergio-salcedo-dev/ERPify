<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Application;

use Erpify\Iam\Session\Domain\SessionId;

/**
 * The single, framework-free seam between the session subsystem and the concrete session backend. The
 * only coupling the domain and use cases have to the framework session is this correlation — the {@see
 * SessionId} minted at login, stashed against the request's session so a later request can recover it.
 *
 * Behind this port the sole adapter reads/writes the `iamSessionId` attribute in the Symfony session bag; it is
 * the twin of {@see \Erpify\Shared\Persistence\Application\TransactionManager} (the bag carries no domain
 * vocabulary, so a port maps it to one and concentrates the `iamSessionId` literal in one place). Swapping the
 * native handler for a shared store (the multi-node forward path) replaces only that adapter — never the
 * minting use case or the admission gate, which both route through {@see get()} / {@see set()}.
 */
interface CurrentSessionReference
{
    /**
     * The current request's correlated session id, or `null` when no session has been minted (a pre-login
     * request, or a native session predating the registry).
     */
    public function get(): ?SessionId;

    public function set(SessionId $sessionId): void;
}
