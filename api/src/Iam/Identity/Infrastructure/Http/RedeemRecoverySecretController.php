<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The recovery-secret redemption: the one edge a locked-out sole administrator has. A `PUBLIC_ACCESS` route
 * INSIDE the `main` firewall (like `/login`, the invitation accept and the password reset), so the login seam
 * can resolve the firewall and mint the first session.
 *
 * **The per-selector budget is spent before any work, and its exhaustion is indistinguishable from a dead
 * secret.** That is the invariant the whole channel rests on: no limiter on this path may be keyed by email
 * or by identity, because those are the namespaces the attack already occupies — an adversary who knows an
 * administrator's address can hold the account locked for the price of a few requests, and a recovery channel
 * keyed the same way would be drained by the same act. A per-selector 429 would also confirm that a selector
 * exists, so exhaustion folds into the same opaque wall as a wrong secret.
 *
 * **It re-authenticates through {@see ReauthenticateDevice}, never the best-effort wrapper the reset flow
 * uses.** That wrapper swallows every `Throwable` and answers anyway, which is correct there — the credential
 * was already replaced, so the mutation stands. Here it would be the worst possible outcome: the session
 * failing silently while the use case goes on to spend the secret would leave the administrator with nothing
 * left to present. The use case takes the login as a closure and runs it BEFORE consuming the row precisely
 * so that this failure costs nothing, and a raised `SessionStoreUnavailable` reaches the client as the 503 it
 * is.
 *
 * Same-origin is enforced by {@see RecoveryOriginListener} (403). The stateless CSRF token is defence in
 * depth on the same terms the reset endpoint states: the manager length-checks the value, then reads
 * same-origin off `Sec-Fetch-Site` when the browser sent it and falls back to `Origin`/`Referer` otherwise —
 * so the token must be present and well-formed, and beyond its shape its value proves nothing. It carries
 * its own id rather than sharing the reset's, so revoking one surface's token cannot silently widen the
 * other.
 *
 * **That id MUST be listed in `csrf_protection.stateless_token_ids`, and omitting it fails this endpoint
 * completely rather than partially.** An unlisted id falls to the SESSION-backed token manager, which the
 * anonymous caller this route exists for cannot satisfy by construction: every legitimate redemption answers
 * 401, not merely a forged one. Measured against the running stack before the id was registered, and nothing
 * static sees it — the attribute compiles, the route registers, the access-control exemption is right, and
 * the controller is simply never reached. `StatelessCsrfTokenIdGateTest` is what refuses the next omission.
 *
 * The secret rides in the BODY, never the query string.
 *
 * Answers **204** with the session cookie. No body, and in particular no rotated secret: re-minting on
 * redemption would spend the only credential of a customer with no shell if the response were lost, and would
 * turn a one-off theft into permanent undetectable access — destroying the single detection property this
 * design has, which is that the owner sees their secret disappear.
 */
#[Route('/recovery/redeem', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsCsrfTokenValid(self::CSRF_TOKEN_ID, tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final readonly class RedeemRecoverySecretController
{
    public const string ROUTE_NAME = 'identity_redeem_recovery_secret';

    public const string CSRF_TOKEN_ID = 'recovery_redeem';

    public function __construct(
        private RedeemRecoverySecret $redeemRecoverySecret,
        private ReauthenticateDevice $reauthenticateDevice,
        private PasswordRecoveryThrottle $throttle,
    ) {
    }

    public function __invoke(
        #[StrictRequestPayload]
        RedeemRecoverySecretRequest $request,
    ): Response {
        // Spent on the selector half alone, before the use case resolves anything, and case-folded so one
        // row cannot answer to thousands of buckets. A malformed presentation has no selector to key, so it
        // keys the whole string: that matches no ROW, but `token_action_per_selector` is shared with the
        // reset completion and the invitation accept, so a dot-less presentation naming another surface's
        // live selector spends THAT link's budget from here. No amplification — the same drain costs the same
        // one request against the other endpoint directly — but the namespace is shared, and saying it can
        // match nothing would be false.
        if (!$this->throttle->allowCompletion(\explode('.', $request->secret, 2)[0])) {
            throw new InvalidRecoverySecret();
        }

        // Handed over as a first-class callable rather than wrapped in an arrow function, and that is not
        // style: a closure declaring `string $email` is a NEW site where a natural person's address is a
        // named parameter, and closure frames carry their arguments into `Throwable::getTrace()`. The
        // method it forwards to is already classified `sensitive` in `api/.person-address-parameter-policy`;
        // forwarding directly keeps the address's declaration sites exactly where that registry can see them.
        $this->redeemRecoverySecret->redeem(
            $request->secret,
            $this->reauthenticateDevice->reauthenticate(...),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
