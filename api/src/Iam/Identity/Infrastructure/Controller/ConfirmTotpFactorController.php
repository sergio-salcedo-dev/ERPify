<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Application\ConfirmTotpFactor;
use Erpify\Iam\Identity\Application\Resource\TotpFactorEnabledResource;
use Erpify\Iam\Identity\Infrastructure\Http\ConfirmTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Security\SecondFactorCodeThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `POST /me/second-factor/totp/confirm` — the signed-in identity enables its pending TOTP factor with a code
 * computed from the secret its enrolment showed, resolving to `/api/v1/me/second-factor/totp/confirm`.
 *
 * {@see SecondFactorCodeThrottle} runs first: a wrong code feeds no lockout, so that per-identity bucket is the
 * only ceiling on guessing one. It is `ordinary` in `api/.credential-proof-policy`, and the reason is the threat
 * rather than a convenience: the secret it confirms was created behind a password re-proof, so a session that
 * reaches this route without having enrolled can at best enable the OWNER's pending authenticator — tightening
 * the account, never handing it to the caller. The code-proof class of that gate, which binds the routes that
 * remove or bypass a factor with a code, arrives with the first such route.
 *
 * Answers **200** with the instant the factor took effect. The secret never appears in this response.
 */
#[Route('/me/second-factor/totp/confirm', name: self::ROUTE_NAME, methods: ['POST'])]
final readonly class ConfirmTotpFactorController
{
    public const string ROUTE_NAME = 'iam_me_confirm_totp';

    public function __construct(
        private ConfirmTotpFactor $confirmTotpFactor,
        private SecondFactorCodeThrottle $secondFactorCodeThrottle,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        SecurityUser $user,
        #[StrictRequestPayload]
        ConfirmTotpFactorRequest $request,
    ): Response {
        $identityId = $user->id() ?? throw new LogicException('An authenticated identity must have an id.');

        $this->secondFactorCodeThrottle->ensureWithinBudget($identityId);

        $factor = $this->confirmTotpFactor->enableWithCode($identityId, $request->code);
        $enabledAt = $factor->enabledAt() ?? throw new LogicException('A confirmed factor must carry its instant.');

        return $this->resourceResponder->respond(
            new TotpFactorEnabledResource($this->atom($enabledAt)),
            Response::HTTP_OK,
        );
    }

    private function atom(DateTimeImmutable $instant): string
    {
        return $instant->format(DateTimeInterface::ATOM);
    }
}
