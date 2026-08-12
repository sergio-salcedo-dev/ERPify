<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The forgot-password endpoint. Public/pre-identity but inside the `main` firewall (mirroring login), so the
 * deferred session-mint seam can resolve the firewall later. Always answers a uniform 202 with no body: it
 * never branches on whether the account exists, so the response cannot enumerate accounts. Same-origin is the
 * primary control ({@see PasswordResetOriginListener}); the stateless CSRF token is defence-in-depth deferred
 * to the surface that introduces it.
 *
 * A target whose per-email budget is exhausted gets the SAME uniform 202 with the work silenced (the use case
 * never runs): announcing the throttle would both reveal which accounts are under attack and let an attacker
 * keep superseding a victim's live token. The silenced path still pays the pre-identity timing floor so the
 * throttled answer is not distinguishable by latency either.
 *
 * Silent to the caller is not silent to the operator, but the projection that records the refusal deliberately
 * does not run here: it is deferred to `kernel.terminate` by {@see RecoveryThrottleAuditListener}, because
 * doing it in-band was measured to make the first refusal of a window slower than the rest. All this branch
 * does is name the target on a request attribute — the listener cannot re-derive it, since the budget is
 * already spent and the response is byte-identical to a served one.
 */
#[Route('/forgot-password', name: 'identity_forgot_password', methods: ['POST'])]
final readonly class RequestPasswordResetController
{
    public function __construct(
        private RequestPasswordReset $requestPasswordReset,
        private PasswordRecoveryThrottle $throttle,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    public function __invoke(
        Request $request,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        ForgotPasswordRequest $payload,
    ): Response {
        if (!$this->throttle->allowRequest($payload->email)) {
            $this->timingFloor->equalise();
            $request->attributes->set(RecoveryThrottleAuditListener::REFUSED_TARGET_ATTRIBUTE, $payload->email);

            return new Response(status: Response::HTTP_ACCEPTED);
        }

        $this->requestPasswordReset->request($payload->email);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
