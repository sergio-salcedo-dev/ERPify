<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeMyPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeMyPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Throwable;

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
 * Both plaintexts stay inside this adapter. The use case receives three closures over the stored credential —
 * "is this the current one?", "is the new one the current one?", and a deferred supplier of the new hash — so
 * the Application and Domain layers only ever handle booleans and an opaque {@see HashedPassword}, and neither
 * password can reach a log line, an exception context or the event store.
 *
 * The programmatic login afterwards is not a convenience: the use case revokes EVERY session of the identity,
 * including the one that made this request, so without it the caller would be signed out by their own password
 * change. {@see ReauthenticateDevice} re-enters the established login path on the `main` firewall — the
 * anti-fixation id regeneration and the session-minting listener — so the device that changed the password walks
 * away with a freshly minted registry session while every other device is left revoked, and it re-reads the
 * aggregate first so a session is never minted for an identity that stopped being admissible while this request
 * was in flight. Running it AFTER the use case is what makes that ordering work; the new session row is minted
 * once the revoke has already swept the old ones, over the credential that was just committed rather than the
 * pre-change snapshot the request was authenticated with.
 *
 * That login is the one step here that cannot be rolled back into a truthful error, so it does not get to
 * decide the status code. The credential is already committed and every session already revoked by the time
 * it runs; answering 5xx because it failed would tell the caller the change did not happen and invite a retry
 * that can no longer succeed — the old password is gone. It is therefore contained: the failure goes to the
 * log at `critical`, the response stays 204, and the caller simply meets the sign-in screen on its next
 * request, where the new password works.
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
        private ReauthenticateDevice $reauthenticateDevice,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        ChangeMyPasswordRequest $request,
    ): Response {
        $this->changeMyPassword->change(
            $user->id() ?? throw new LogicException('An authenticated identity must have an id.'),
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

        try {
            $this->reauthenticateDevice->reauthenticate($user->getUserIdentifier());
        } catch (Throwable $throwable) {
            // The identity is not named in the context on purpose: the id is the subject's own, and a log
            // line is a sink no erasure path reaches. The request's correlation id already ties this entry
            // to the caller for as long as the request trail exists.
            $this->logger->critical(
                'The credential was replaced but the caller could not be re-authenticated.',
                ['exception' => $throwable],
            );
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
