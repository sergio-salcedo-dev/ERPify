<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeMyPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeMyPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDeviceBestEffort;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `POST /me/password` — the signed-in identity replaces its own credential. Mounts under `/api/v1`, resolving to
 * `/api/v1/me/password`, and the firewall's `^/api` rule already demands `IS_AUTHENTICATED_FULLY`, so an
 * anonymous caller never reaches it. There is no `#[IsGranted]` by design, for the same reason `GET /me` has
 * none: the subject is always the caller's own identity, so there is no resource another identity could govern.
 *
 * No CSRF token either, matching the other authenticated writes. The control here is stronger than a token: the
 * request must carry the current password, which a cross-site forgery does not know, and the endpoint is
 * JSON-only ({@see StrictRequestPayload}) so no form post can reach it.
 *
 * {@see CurrentPasswordProofThrottle} runs before anything else, keyed on the identity rather than the IP or the
 * session: the attacker this endpoint fears already holds a session, so a budget scoped to either of those is
 * one they renew at will. It is spent before any credential work, so a saturated identity pays no KDF — though
 * not before the payload is read: the argument resolver maps and validates the body first, so a malformed
 * request is refused 422 without touching the budget at all. A wrong current password deliberately does not
 * feed the persisted lockout — marking failures from a
 * route that requires a live session would let a stolen session lock the owner out — so this budget is the only
 * per-identity ceiling here, and the 403 it does not stop leaves a `SECURITY` audit row behind it
 * ({@see \Erpify\Iam\Identity\Infrastructure\Http\InvalidCurrentPasswordAuditListener}).
 *
 * Both plaintexts stay inside this adapter. The use case receives three closures over the stored credential —
 * "is this the current one?", "is the new one the current one?", and a deferred supplier of the new hash — so
 * the Application and Domain layers only ever handle booleans and an opaque {@see HashedPassword}, and neither
 * password can reach a log line, an exception context or the event store.
 *
 * The programmatic login afterwards is not a convenience: the use case revokes EVERY session of the identity,
 * including the one that made this request, so without it the caller would be signed out by their own password
 * change. {@see ReauthenticateDeviceBestEffort} re-enters the established login path on the `main` firewall —
 * the anti-fixation id regeneration and the session-minting listener — so the device that changed the password
 * walks away with a freshly minted registry session while every other device is left revoked, and it re-reads
 * the aggregate first so a session is never minted for an identity that stopped being admissible while this
 * request was in flight. Running it AFTER the use case is what makes that ordering work; the new session row is
 * minted once the revoke has already swept the old ones, over the credential that was just committed rather
 * than the pre-change snapshot the request was authenticated with.
 *
 * Its containment is the collaborator's, not this controller's, because all three flows that re-authenticate
 * owe the caller the same treatment and a copy each is how they would stop agreeing.
 *
 * Answers **204** with the refreshed session cookie and no body.
 */
#[Route('/me/password', name: self::ROUTE_NAME, methods: ['POST'])]
final readonly class ChangeMyPasswordController
{
    public const string ROUTE_NAME = 'iam_me_change_password';

    public function __construct(
        private ChangeMyPassword $changeMyPassword,
        private PasswordHasher $passwordHasher,
        private ReauthenticateDeviceBestEffort $reauthenticateDevice,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload]
        ChangeMyPasswordRequest $request,
    ): Response {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->currentPasswordProofThrottle->ensureWithinBudget($identityId);

        $this->changeMyPassword->change(
            $identityId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->newPassword,
                $stored->toString(),
            ),
            fn (): HashedPassword => HashedPassword::fromHash($this->passwordHasher->hash($request->newPassword)),
        );

        $this->reauthenticateDevice->reauthenticate($user->getUserIdentifier());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
