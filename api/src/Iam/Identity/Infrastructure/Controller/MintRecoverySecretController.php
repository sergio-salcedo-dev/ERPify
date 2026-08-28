<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use DateTimeInterface;
use Erpify\Iam\Identity\Application\MintRecoverySecret;
use Erpify\Iam\Identity\Application\Resource\MintedRecoverySecretResource;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\MintRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `POST /me/recovery-secret` — the signed-in identity mints its own recovery credential, resolving to
 * `/api/v1/me/recovery-secret`. The firewall's `^/api` rule already demands `IS_AUTHENTICATED_FULLY`, so an
 * anonymous caller never reaches it. There is no `#[IsGranted]` by design, for the reason `GET /me` has none:
 * the subject is always the caller's own identity, so there is no resource another identity could govern —
 * and deliberately no ROLE gate either, because the sole administrator this channel exists for is not
 * distinguishable by role from anyone else who could one day be locked out.
 *
 * {@see CurrentPasswordProofThrottle} runs first, and it is the SAME per-identity bucket `POST /me/password`
 * spends. That sharing is the security decision this endpoint depends on rather than an economy: a wrong
 * `currentPassword` on neither route feeds the persisted lockout, so this limiter is the only ceiling on
 * guessing that credential from a live session, and a bucket of its own here would hand an attacker holding
 * a stolen session twice the guesses per window against the same password.
 *
 * No CSRF token, matching the other authenticated writes: the control here is stronger than a token, since
 * the request must carry the current password, which a cross-site forgery does not know, and the endpoint is
 * JSON-only ({@see StrictRequestPayload}) so no form post can reach it.
 *
 * The plaintext stays inside this adapter on the way in — the use case receives a closure over the stored
 * {@see HashedPassword} and only ever learns "matches" — and leaves in the 201 body on the way out. That
 * response is the ONE place the minted secret is ever legible; nothing logs it and nothing can re-derive it.
 * Answers **201** with the secret, its minting instant and its expiry.
 */
#[Route('/me/recovery-secret', name: self::ROUTE_NAME, methods: ['POST'])]
final readonly class MintRecoverySecretController
{
    public const string ROUTE_NAME = 'iam_me_mint_recovery_secret';

    public function __construct(
        private MintRecoverySecret $mintRecoverySecret,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload(acceptFormat: ['json'])]
        MintRecoverySecretRequest $request,
    ): Response {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->currentPasswordProofThrottle->ensureWithinBudget($identityId);

        $generated = $this->mintRecoverySecret->mint(
            $identityId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

        return $this->resourceResponder->respond(
            new MintedRecoverySecretResource(
                $generated->plaintext(),
                $generated->secret->getCreatedAt()->format(DateTimeInterface::ATOM),
                $generated->secret->expiresAt()->format(DateTimeInterface::ATOM),
            ),
            Response::HTTP_CREATED,
        );
    }
}
