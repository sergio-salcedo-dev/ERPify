<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\CompletePasswordReset;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reset-password endpoint. Hashes the new credential here — hashing is an Infrastructure concern, so the
 * use case receives an already-opaque {@see HashedPassword} (as {@see \Erpify\Iam\Identity\Application\
 * CreateUser} does); paying the hash before the use case also keeps the credential cost uniform across valid
 * and dead tokens. Success answers 204 with NO cookie: minting a session (auto-login + id regeneration) is a
 * deferred seam, so the user signs in normally afterwards. A dead token → 400 `invalid-token`; a suspended /
 * deactivated identity → 403.
 */
#[Route('/reset-password', name: 'identity_reset_password', methods: ['POST'])]
final readonly class CompletePasswordResetController
{
    public function __construct(
        private CompletePasswordReset $completePasswordReset,
        private PasswordHasher $passwordHasher,
    ) {
    }

    public function __invoke(#[MapRequestPayload] ResetPasswordRequest $request): Response
    {
        $this->completePasswordReset->complete(
            $request->token,
            HashedPassword::fromHash($this->passwordHasher->hash($request->password)),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
