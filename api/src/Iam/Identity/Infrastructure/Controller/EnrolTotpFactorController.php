<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\EnrolTotpFactor;
use Erpify\Iam\Identity\Application\Resource\TotpEnrolmentResource;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Http\EnrolTotpFactorRequest;
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
 * `POST /me/second-factor/totp` — the signed-in identity starts its own TOTP enrolment, resolving to
 * `/api/v1/me/second-factor/totp`. The firewall's `^/api` rule already demands `IS_AUTHENTICATED_FULLY`; there is
 * no `#[IsGranted]` and no role gate, for the reason `POST /me/recovery-secret` has none: the subject is always
 * the caller's own identity, and the factor is optional for everyone, administrators included.
 *
 * It creates a credential of the caller's own, so it is `credential-affecting` in `api/.credential-proof-policy`:
 * {@see CurrentPasswordProofThrottle} runs first and spends the SAME per-identity bucket as the other password
 * re-proofs, then the use case re-proves the password before it consults the factor. A stolen session without the
 * password can neither enrol an authenticator of its own nor learn whether the account has one.
 *
 * No CSRF token, matching the other authenticated writes: the request carries the current password, which a
 * cross-site forgery does not know, and the endpoint is JSON-only ({@see StrictRequestPayload}).
 *
 * Answers **201** with the secret in base32 and the `otpauth://` URI — the ONE response that ever carries them.
 */
#[Route('/me/second-factor/totp', name: self::ROUTE_NAME, methods: ['POST'])]
final readonly class EnrolTotpFactorController
{
    public const string ROUTE_NAME = 'iam_me_enrol_totp';

    public function __construct(
        private EnrolTotpFactor $enrolTotpFactor,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload]
        EnrolTotpFactorRequest $request,
    ): Response {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->currentPasswordProofThrottle->ensureWithinBudget($identityId);

        $enrolment = $this->enrolTotpFactor->enrol(
            $identityId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

        return $this->resourceResponder->respond(
            new TotpEnrolmentResource($enrolment->encodedSecret(), $enrolment->provisioningUri()),
            Response::HTTP_CREATED,
        );
    }
}
