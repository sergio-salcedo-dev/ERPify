<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\RevokeRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `POST /me/recovery-secret/revoke` — the owner destroys their own recovery credential, resolving to
 * `/api/v1/me/recovery-secret/revoke`. The firewall's `^/api` rule already demands `IS_AUTHENTICATED_FULLY`,
 * so an anonymous caller never reaches it. No `#[IsGranted]`, like its siblings under `/me`: the subject is
 * always the caller's own identity, so there is no resource another identity could govern.
 *
 * **This is the eviction the design owes in exchange for never destroying a secret silently.** A password
 * change leaves a live secret standing, deliberately, because a routine rotation must not take out the
 * recovery channel of someone with no shell to notice; the consequence is that revocation is the only way a
 * holder stops being one short of redeeming it or waiting out the decade.
 *
 * **It costs a `currentPassword`, because destroying the secret IS the attack this channel exists to answer.**
 * The act is permanent and its owner cannot undo it from outside a session, so a request that needs only a
 * cookie would let whoever stole one retire the recovery credential, read the address off `GET /me`, and then
 * hold the account closed on the email-keyed lockout — the precise situation the recovery secret is the way
 * out of. Minting and replacing the password both re-prove the credential; leaving the destructive write as
 * the one that does not would make it the cheapest of the three.
 *
 * {@see CurrentPasswordProofThrottle} runs first, and it is the SAME per-identity bucket the other two spend.
 * That sharing is a security decision rather than an economy: no wrong `currentPassword` on any of the three
 * routes feeds the persisted lockout, so this limiter is the only ceiling on guessing that credential from a
 * live session, and a bucket of its own here would hand an attacker holding a stolen session more guesses per
 * window against the same password. The cost is stated rather than hidden — an owner who has drained the
 * budget mistyping elsewhere waits for the window before they can revoke a secret they believe is compromised
 * — and it is the smaller half of the trade, because nothing is destroyed by waiting and the window refills.
 *
 * The proof travels in a BODY. Every request header but `Referer`, and the URL path, are written to the
 * container access log in clear before the application has validated anything, and that sink has no TTL and no
 * erasure owner; the query string is stripped at the edge, but by a literal `?` that a percent-encoded one
 * never meets. A body is the only carrier that reaches this method and nothing else. `POST … /revoke` rather
 * than a `DELETE` carrying one, because the shared HTTP client port the consumer calls this through declares
 * no body on its delete, and widening a shared contract for a single caller buys nothing here.
 *
 * No CSRF token, matching the other authenticated writes: the control here is stronger than a token, since the
 * request must carry the current password, which a cross-site forgery does not know, and the endpoint is
 * JSON-only ({@see StrictRequestPayload}) so no form post can reach it.
 *
 * The plaintext stays inside this adapter: the use case receives a closure over the stored
 * {@see HashedPassword} and only ever learns "matches".
 *
 * Answers **204** whether or not a row was there. The idempotence discloses nothing — the caller is the owner,
 * has just re-proved their credential, and already knows from the profile read — and it spares the client a
 * 404 for a state it asked to reach.
 */
#[Route('/me/recovery-secret/revoke', name: self::ROUTE_NAME, methods: ['POST'])]
final readonly class RevokeMyRecoverySecretController
{
    public const string ROUTE_NAME = 'iam_me_revoke_recovery_secret';

    public function __construct(
        private RevokeRecoverySecret $revokeRecoverySecret,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        RevokeRecoverySecretRequest $request,
    ): Response {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->currentPasswordProofThrottle->ensureWithinBudget($identityId);

        $this->revokeRecoverySecret->revoke(
            $identityId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
