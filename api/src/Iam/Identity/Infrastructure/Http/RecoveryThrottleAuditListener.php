<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Projects a refused recovery request onto the `security` trail on `kernel.terminate` — after the response is
 * on the wire.
 *
 * THE TIMING IS THE POINT, and it was measured rather than assumed. Writing the row in-band made the first
 * refusal of a budget window cost an indexed `SELECT` plus an INSERT while every later refusal in that window
 * cost neither, because the claim short-circuits first. Over twenty addresses that differential was **14.70 ms
 * of median, positive in twenty pairs out of twenty**, against 5.10 ms of baseline noise — a branch a caller
 * could time to learn whether an exhaustion for that address had already been observed. The uniform 202 exists
 * precisely so nothing about this endpoint can be read off the response, and latency is part of the response.
 * Deferring the whole projection past `terminate` removes the differential from the client's view instead of
 * paying it down: no work is added to any branch, and the budget, the bound and the attribution are unchanged.
 * {@see \Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessLogAuditListener} takes the same route for
 * the same reason.
 *
 * THE CONTROLLER HANDS OVER A REQUEST ATTRIBUTE, and that indirection is bought, not free. A listener cannot
 * re-derive the refusal: the throttle has already consumed the budget, so asking it again would answer about a
 * second consumption, and the response is byte-identical to a served one. The attribute is the only honest
 * channel, and it is underscore-prefixed like the framework's own so nothing mistakes it for a controller
 * argument. Weighed against a measured timing channel, an explicit request attribute is the cheaper cost.
 */
final readonly class RecoveryThrottleAuditListener
{
    /**
     * Set by {@see RequestPasswordResetController} on the refused branch, carrying the address the refusal was
     * about. It never leaves the request: the recorder resolves it to an identity and writes only that.
     */
    public const string REFUSED_TARGET_ATTRIBUTE = '_recovery_throttle_refused_target';

    public function __construct(
        private RecordRecoveryThrottleAuditBestEffort $recordThrottleAudit,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * No main-request guard: {@see TerminateEvent} is `final` and passes `MAIN_REQUEST` to its parent
     * unconditionally, so the usual `isMainRequest()` check on this event can never be false.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $target = $request->attributes->get(self::REFUSED_TARGET_ATTRIBUTE);

        if (!\is_string($target)) {
            return;
        }

        $this->recordWithin($request, $target);
    }

    /**
     * `kernel.terminate` runs after `finishRequest()` has popped the request, so the entry factory would
     * otherwise seal a `system` actor, a fresh (wrong) correlation id and a null ip/user-agent. Re-establishing
     * the request for the emission keeps the row identical to the one an in-band write would have produced —
     * which is what makes deferring it a change of timing only, and not a change of content.
     */
    private function recordWithin(Request $request, string $target): void
    {
        $this->requestStack->push($request);

        try {
            $this->recordThrottleAudit->record($target);
        } finally {
            $this->requestStack->pop();
        }
    }
}
