<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\CompletePasswordReset;
use Erpify\Iam\Identity\Domain\Exception\InvalidResetToken;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The reset-password endpoint. Hashing is an Infrastructure concern, so it stays here — but wrapped in a
 * deferred supplier the use case only invokes once the token has resolved live, so a dead link never costs a
 * KDF run (an unauthenticated argon2id per garbage POST would be a CPU-amplification vector). A dead token →
 * 400 `invalid-token`; a suspended / deactivated identity → 403.
 *
 * On success it establishes the session: a `PUBLIC_ACCESS` route INSIDE the `main` firewall (like `/login` and
 * the invitation accept), so {@see Security::login()} resolves the firewall and reuses the established login
 * path — the anti-fixation `migrate(true)` regeneration and the session-minting listener — rather than
 * duplicating it. All the identity's prior sessions were revoked inside the use case, so the freshly minted one
 * is the only live session (reset everywhere, then sign in here). Answers **204** with the session cookie.
 *
 * CSRF is defence in depth, not the primary control: same-origin is enforced by {@see PasswordResetOriginListener}
 * (403), and the reset token itself is single-use and opaque. The native stateless double-submit token
 * (`#[IsCsrfTokenValid]`, session-free, same-origin) is the second layer, sharing the invitation-accept flow's
 * `check_header`-off config.
 */
#[Route('/reset-password', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsCsrfTokenValid(self::CSRF_TOKEN_ID)]
final readonly class CompletePasswordResetController
{
    public const string ROUTE_NAME = 'identity_reset_password';

    public const string CSRF_TOKEN_ID = 'password_reset';

    private const string FIREWALL = 'main';

    public function __construct(
        private CompletePasswordReset $completePasswordReset,
        private PasswordHasher $passwordHasher,
        private UserProvider $userProvider,
        private Security $security,
        private PasswordRecoveryThrottle $throttle,
    ) {
    }

    public function __invoke(#[MapRequestPayload] ResetPasswordRequest $request): Response
    {
        // Per-selector brute-force budget, consumed before any work: exhaustion folds into the SAME opaque
        // invalid-token wall as a dead link — a per-selector 429 would confirm the selector exists.
        if (!$this->throttle->allowCompletion(\explode('.', $request->token, 2)[0])) {
            throw new InvalidResetToken();
        }

        $email = $this->completePasswordReset->complete(
            $request->token,
            fn (): HashedPassword => HashedPassword::fromHash($this->passwordHasher->hash($request->password)),
        );

        // Post-commit: authenticate the identity with the just-set credential so LoginSuccessEvent fires once,
        // reusing the native id regeneration and the session-minting listener.
        $this->security->login($this->userProvider->loadUserByIdentifier($email), firewallName: self::FIREWALL);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
