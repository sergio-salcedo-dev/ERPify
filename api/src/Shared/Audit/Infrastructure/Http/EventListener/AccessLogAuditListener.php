<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http\EventListener;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditPolicy;
use Erpify\Shared\Audit\Domain\HttpInteraction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Generic access-log capture. On `kernel.terminate` — after the response is sent, so it adds zero
 * latency to the request path — it hands every successful main `/api/*` interaction to
 * {@see AuditPolicy} and, when the policy says so, emits it through the {@see AuditLogger} seam. The
 * hook only captures context (route, method, response outcome) and decides nothing itself: what is
 * auditable and at what level is the policy's call, and the action it dictates lands per route.
 *
 * A non-successful response is never an activity "view" — a permission denial is captured as `security`
 * by the access-denied listener instead — so the hook stays silent for it. It is purely additive: it
 * never sets or mutates the response and never throws into the request (the AuditLogger swallows
 * activity failures by design).
 */
final readonly class AccessLogAuditListener
{
    private const string API_PATH_PREFIX = '/api/';

    public function __construct(
        private AuditPolicy $policy,
        private AuditLogger $auditLogger,
        private RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (
            !\str_starts_with($request->getPathInfo(), self::API_PATH_PREFIX)
            || !$event->getResponse()->isSuccessful()
        ) {
            return;
        }

        $decision = $this->policy->decide(new HttpInteraction($this->routeOf($request), $request->getMethod()));

        if (null === $decision->action || !$decision->level instanceof AuditLevel) {
            return;
        }

        $this->emitWithin($request, $decision->action, $decision->level);
    }

    /**
     * `kernel.terminate` runs after `finishRequest()` has popped the request, so the shared seal would
     * otherwise resolve a `system` actor, a fresh (wrong) correlation id and a null ip/user-agent.
     * Re-establishing the request for the emission lets {@see AuditLogger} seal the same anonymous
     * actor, request correlation id and client ip/user-agent it would have sealed mid-request.
     */
    private function emitWithin(Request $request, string $action, AuditLevel $level): void
    {
        $this->requestStack->push($request);

        try {
            $this->auditLogger->log($action, $level);
        } finally {
            $this->requestStack->pop();
        }
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }
}
