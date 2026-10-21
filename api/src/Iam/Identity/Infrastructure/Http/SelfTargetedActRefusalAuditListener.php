<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Exception\SelfTargetedActForbidden;
use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every refused self-targeted administrative act — an administrator aiming a role change, a status
 * transition, an unlock or an erasure at their own identity — as a `security` audit entry. Each refusal is a
 * 409 thrown before the use case opens its transaction, so it writes no row and publishes no event of its own,
 * and the generic hook records successful reads only: nothing else in the trail sees it. That silence is the
 * wrong default for exactly these requests, since a stolen administrator session turning the users surface on
 * its own identity is the signal an operator needs.
 *
 * **One listener on {@see SelfTargetedActForbidden}, not four calls in four use cases.** The refusal happens
 * before any lock or row write, and it must keep happening there; a write inside each use case would have to be
 * placed between the check and the throw in four places, and a fifth refusal would be unrecorded until somebody
 * remembered. Matching the marker makes the record a property of the refusal itself. The two siblings that
 * record a refusal — {@see InvalidCurrentPasswordAuditListener} and
 * {@see \Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessDeniedAuditListener} — made the same
 * choice for the same reason.
 *
 * **One action, the refusal in `metadata`.** `action` stays the cardinality-1 `SELF_TARGETED_ACT_REFUSED`, so
 * "every self-targeted refusal" is one indexed equality an alert can aggregate over, while `metadata` carries the
 * refusal's problem `type` (a closed constant of the exception, never request input) and the route. Nothing
 * else: no request body, and not the exception's `context`, whose `userId` is the actor's own id.
 *
 * **Resource-less**, for the reason {@see InvalidCurrentPasswordAuditListener} gives: the target is by
 * definition the actor, so naming it as the resource would write `actor_id == resource_id` onto the
 * `audit_log.resource_id` person axis for no forensic gain the sealed `actor_id` does not already carry. The row
 * therefore sits on the actor axis alone, which the identity erasure anonymises with every other row the subject
 * authored.
 *
 * **No budget of its own**, unlike {@see InvalidCurrentPasswordAuditListener}, whose row would be a write
 * amplifier on an endpoint any session can reach. Only a session already holding a `users.*` permission reaches
 * these refusals; that session can already write one unbudgeted `security` row per unlock call on another
 * target, or per 403. The action is `ordinary`, so the retention prune bounds how long a row lives — not how
 * many such a session writes between two sweeps, which is as unbounded here as on those routes.
 *
 * **A failed write is never swallowed**, as no `security` write is: it replaces the event's throwable
 * through {@see RequestBoundarySecurityAudit::recordOnException()}, so the responder answers a Problem Details
 * 5xx instead of the 409, rather than letting a refusal complete unrecorded. It is handed to the event and
 * never thrown, because a throwable leaving a `kernel.exception` listener escapes HttpKernel with no Problem
 * Details at all; and the seam refuses to write inside a leaked transaction, whose rollback would take the row
 * with it. The best-effort writers in this context swallow because their work has already committed and a 5xx
 * would invite a retry of something that happened; a refusal has committed nothing, so the only thing a
 * swallow would save is the status code, at the price of the record.
 *
 * Priority above the Problem Details responder, which stops propagation once it sets the response; this
 * listener never sets one. A written refusal leaves the event untouched, so the 409 body is the responder's
 * own; a refused or failed write is the one case where the throwable changes, and the 5xx above is the point.
 * The throwable is matched directly: the four use cases throw it unwrapped.
 */
final readonly class SelfTargetedActRefusalAuditListener
{
    public const int PRIORITY = 32;

    private const string ACTION = 'SELF_TARGETED_ACT_REFUSED';

    public function __construct(
        private RequestBoundarySecurityAudit $securityAudit,
        private ApiRequestMatcher $apiRequestMatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->apiRequestMatcher->matches($request)) {
            return;
        }

        $refusal = $event->getThrowable();

        if (!$refusal instanceof SelfTargetedActForbidden) {
            return;
        }

        $this->securityAudit->recordOnException(
            $event,
            self::ACTION,
            ['refusal' => $refusal->type(), 'route' => $this->routeOf($request)],
        );
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }
}
