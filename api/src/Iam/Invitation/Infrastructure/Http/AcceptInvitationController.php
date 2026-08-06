<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDeviceBestEffort;
use Erpify\Iam\Invitation\Application\AcceptInvitation;
use Erpify\Iam\Invitation\Domain\Exception\InvalidToken;
use Erpify\Iam\Invitation\Infrastructure\Security\InvitationAcceptThrottle;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * `POST /api/v1/backoffice/invitations/accept` — the one public write of the invitation flow. It is a
 * `PUBLIC_ACCESS` route INSIDE the `main` firewall (like `/login`), so
 * {@see ReauthenticateDeviceBestEffort} resolves the
 * firewall and the established login path — the anti-fixation session `migrate(true)` and
 * {@see \Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener} — is reused rather than
 * duplicated.
 *
 * Ordering is load-bearing: the domain flips (identity activation + invitation retire) commit INSIDE
 * {@see AcceptInvitation}'s transaction FIRST; only then does the login run, on the committed ACTIVE identity,
 * so a session is never minted for a half-accepted invitation. Password hashing is an Infrastructure concern
 * done here — but wrapped in a deferred supplier the use case only invokes once the token has resolved live,
 * so a dead link never costs a KDF run (an unauthenticated argon2id per garbage POST would be a
 * CPU-amplification vector).
 *
 * CSRF is defence in depth, not the primary control: same-origin is enforced by
 * {@see AcceptInvitationOriginListener} (403), and the token itself is single-use and opaque. The native
 * stateless CSRF token (`#[IsCsrfTokenValid]`, session-free) is the second layer. What it proves is narrow:
 * the manager length-checks the value, then reads same-origin off `Sec-Fetch-Site` when the browser sent that
 * header and falls back to comparing `Origin`/`Referer` only when it did not; the alternative path is a
 * double-submit cookie, which this client never sets. So the token must be PRESENT, but its value is never
 * verified against anything.
 *
 * The header carries it because the body is the application contract — {@see StrictRequestPayload} refuses
 * any member the payload does not declare, and a transport credential is not something
 * {@see AcceptInvitationRequest} should have to model. It also fails fast: a missing `X-CSRF-Token` is
 * refused before origin is consulted. What it does NOT add is a barrier independent of origin — a
 * cross-origin caller is already refused by the origin check above, and anyone able to forge `Origin` can
 * set a custom header just as easily. The token alone never admits a request.
 */
#[Route('/invitations/accept', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsCsrfTokenValid(self::CSRF_TOKEN_ID, tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final readonly class AcceptInvitationController
{
    public const string ROUTE_NAME = 'iam_invitation_accept';

    public const string CSRF_TOKEN_ID = 'invitation_accept';

    public function __construct(
        private AcceptInvitation $acceptInvitation,
        private PasswordHasher $passwordHasher,
        private ReauthenticateDeviceBestEffort $reauthenticateDevice,
        private InvitationAcceptThrottle $throttle,
    ) {
    }

    public function __invoke(#[StrictRequestPayload(acceptFormat: ['json'])] AcceptInvitationRequest $request): Response
    {
        // Per-selector brute-force budget, consumed before any work: exhaustion folds into the SAME opaque
        // invalid-token wall as a dead link — a per-selector 429 would confirm the selector exists.
        if (!$this->throttle->allowAccept(\explode('.', $request->token, 2)[0])) {
            throw new InvalidToken();
        }

        $accepted = $this->acceptInvitation->accept(
            $request->token,
            fn (): HashedPassword => HashedPassword::fromHash($this->passwordHasher->hash($request->password)),
        );

        // Post-commit: authenticate the now-ACTIVE identity through the real firewall so LoginSuccessEvent
        // fires once — reusing the native id regeneration and the session-minting listener, and re-reading
        // the aggregate so the admission wall is weighed in full rather than the half a programmatic login runs.
        $this->reauthenticateDevice->reauthenticate($accepted->email);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
