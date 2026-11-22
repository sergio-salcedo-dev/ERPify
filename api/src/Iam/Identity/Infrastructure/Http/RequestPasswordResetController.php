<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The forgot-password endpoint. Public/pre-identity but inside the `main` firewall (mirroring login), so the
 * deferred session-mint seam can resolve the firewall later. Always answers a uniform 202 with no body: it
 * never branches on whether the account exists, so the response cannot enumerate accounts. Same-origin is the
 * primary control ({@see RecoveryOriginListener}); the stateless CSRF token is defence-in-depth deferred
 * to the surface that introduces it.
 *
 * NO BRANCH DOES THE WORK BEFORE THE RESPONSE. Every address — unknown, malformed, non-`ACTIVE`, `ACTIVE` or
 * over budget — pays the same in-band operations: the budget claim, the pre-identity timing floor and one
 * request attribute. The reset itself (a `FOR UPDATE` transaction and a synchronous mail for an `ACTIVE`
 * identity) is deferred to `kernel.terminate` by {@see PasswordResetRequestListener}, because an in-band
 * difference between the branches is a timing oracle on account existence (D10).
 *
 * A target whose per-email budget is exhausted gets the SAME uniform 202 with the reset silenced (the listener
 * is never handed the address): announcing the throttle would both reveal which accounts are under attack and
 * let an attacker keep superseding a victim's live token.
 *
 * Silent to the caller is not silent to the operator, but the projection that records the refusal deliberately
 * does not run here either: it is deferred to `kernel.terminate` by {@see RecoveryThrottleAuditListener},
 * because doing it in-band was measured to make the first refusal of a window slower than the rest. The
 * attribute is the only honest channel — the listener cannot re-derive the target, since the budget is already
 * spent and the response is byte-identical to a served one.
 */
#[Route('/forgot-password', name: 'identity_forgot_password', methods: ['POST'])]
final readonly class RequestPasswordResetController
{
    public function __construct(
        private PasswordRecoveryThrottle $throttle,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    public function __invoke(
        Request $request,
        #[StrictRequestPayload]
        ForgotPasswordRequest $payload,
    ): Response {
        $allowed = $this->throttle->allowRequest($payload->email);

        $this->timingFloor->equalise();

        // One attribute, whichever way the budget fell: the in-band work of the two branches is the same set
        // of operations, and what differs (the reset itself, or the audit projection of a refusal) runs on
        // `kernel.terminate`.
        $attribute = PasswordResetRequestListener::TARGET_ATTRIBUTE;

        if (!$allowed) {
            $attribute = RecoveryThrottleAuditListener::REFUSED_TARGET_ATTRIBUTE;
        }

        $request->attributes->set($attribute, $payload->email);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
