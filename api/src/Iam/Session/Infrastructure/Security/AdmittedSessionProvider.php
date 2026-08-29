<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the session a request was admitted with, or refuses the request — the one question both
 * `/sessions` controllers must answer before they can do anything.
 *
 * **The `Request` arrives as an argument, and what that buys is narrower than it looks.** It does NOT make
 * this callable outside a request: {@see CurrentSessionReference}'s only adapter reads `RequestStack` itself
 * and answers `null` once the stack is popped, so a caller in a `kernel.terminate` listener is refused here
 * one line before it would have reached the store. That is a precondition of this collaborator, stated rather
 * than defended against. What the argument does buy is that the attribute is read off the request the caller
 * holds — a sub-request carries its own attributes — so this class never has to form a second opinion about
 * which request is current.
 *
 * **`readonly` covers instance state and only that.** FrankenPHP runs in worker mode, so this service and the
 * container holding it outlive the request, and a remembered session would be handed to the NEXT one; a
 * `readonly` class cannot grow the property that would hold it. A `static` inside the method body still can,
 * and no gate here sees that — the guarantee is worth having and is not the whole of the problem.
 *
 * **{@see SessionAdmissionGate} keeps its own resolution, and that is deliberate.** The two read alike, but
 * the gate additionally decides ADMISSION — it drops the native session on refusal, which is what makes a
 * stale cookie cheap on every subsequent request — while this one only answers a controller. Folding them
 * together would trade two honest statements of two different responsibilities for one abstraction
 * parameterised by how it fails.
 */
final readonly class AdmittedSessionProvider
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private SessionRepository $sessions,
    ) {
    }

    /**
     * @throws SessionNoLongerActive   when the request carries no live correlation, or the correlated row is
     *                                 gone, revoked or expired
     * @throws SessionStoreUnavailable when the store cannot be reached — deliberately not caught, so an
     *                                 outage stays a 503 rather than being reported as an expired session
     */
    public function requireAdmitted(Request $request): AdmittedSession
    {
        $sessionId = $this->currentSession->get();

        if (!$sessionId instanceof SessionId) {
            throw SessionNoLongerActive::forRequest();
        }

        // The gate loaded this row at `kernel.request` and published it; the lookup is the fallback for the
        // requests it did not run for — a sub-request, or a caller reaching a controller by another route.
        $session = SessionAdmissionGate::admittedSession($request)
            ?? $this->sessions->findActiveById($sessionId);

        if (!$session instanceof Session) {
            throw SessionNoLongerActive::forRequest();
        }

        return new AdmittedSession($sessionId, $session);
    }
}
