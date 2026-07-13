<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The forgot-password endpoint. Public/pre-identity but inside the `main` firewall (mirroring login), so the
 * deferred session-mint seam can resolve the firewall later. Always answers a uniform 202 with no body: it
 * never branches on whether the account exists, so the response cannot enumerate accounts. Same-origin is the
 * primary control ({@see PasswordResetOriginListener}); the stateless CSRF token is defence-in-depth deferred
 * to the surface that introduces it.
 */
#[Route('/forgot-password', name: 'identity_forgot_password', methods: ['POST'])]
final readonly class RequestPasswordResetController
{
    public function __construct(private RequestPasswordReset $requestPasswordReset)
    {
    }

    public function __invoke(#[MapRequestPayload] ForgotPasswordRequest $request): Response
    {
        $this->requestPasswordReset->request($request->email);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
