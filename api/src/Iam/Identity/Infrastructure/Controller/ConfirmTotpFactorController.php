<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Application\ConfirmTotpFactor;
use Erpify\Iam\Identity\Application\Resource\TotpFactorEnabledResource;
use Erpify\Iam\Identity\Infrastructure\Http\ConfirmTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDeviceBestEffort;
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
 * {@see SecondFactorCodeThrottle} runs first, as an attenuator: `lock_factory: null` lets a burst overrun it and
 * the filesystem pool resets on deploy. The wall on guessing the code is the enrolment itself — a pending factor
 * closes after ten minutes or five wrong codes, counted on its row and committed although each refused request
 * fails, and only a new enrolment behind the password restarts it. It is `ordinary` in
 * `api/.credential-proof-policy` (accepted by the owner, ADR `two-step-verification.md` D3), and the reason is the
 * threat rather than a convenience: the secret it confirms was created behind a password re-proof, so a session
 * that reaches this route without having enrolled can at best enable the OWNER's pending authenticator. A route
 * that removes or bypasses a factor with a code is a different act and is not classified this way.
 *
 * Once the factor is enabled the use case has revoked every session of the identity; this action then signs the
 * device that asked back in ({@see ReauthenticateDeviceBestEffort}), as `POST /me/password` does, so it is the one
 * device left signed in.
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
        private ReauthenticateDeviceBestEffort $reauthenticateDevice,
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

        $this->reauthenticateDevice->reauthenticate($user->getUserIdentifier());

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
