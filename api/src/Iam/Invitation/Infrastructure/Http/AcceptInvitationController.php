<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Iam\Invitation\Application\AcceptInvitation;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * `POST /api/v1/backoffice/invitations/accept` — the one public write of the invitation flow. It is a
 * `PUBLIC_ACCESS` route INSIDE the `main` firewall (like `/login`), so {@see Security::login()} resolves the
 * firewall and the established login path — the anti-fixation session `migrate(true)` and
 * {@see \Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener} — is reused rather than
 * duplicated.
 *
 * Ordering is load-bearing: the domain flips (identity activation + invitation retire) commit INSIDE
 * {@see AcceptInvitation}'s transaction FIRST; only then does the login run, on the committed ACTIVE identity,
 * so a session is never minted for a half-accepted invitation. Password hashing is an Infrastructure concern
 * done here, so the use case stays a pure Application orchestrator over a {@see HashedPassword}.
 *
 * CSRF is defence in depth, not the primary control: same-origin is enforced by
 * {@see AcceptInvitationOriginListener} (403), and the token itself is single-use and opaque. The native
 * stateless double-submit token (`#[IsCsrfTokenValid]`, session-free, same-origin) is the second layer.
 */
#[Route('/invitations/accept', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsCsrfTokenValid(self::CSRF_TOKEN_ID)]
final readonly class AcceptInvitationController
{
    public const string ROUTE_NAME = 'iam_invitation_accept';

    public const string CSRF_TOKEN_ID = 'invitation_accept';

    private const string FIREWALL = 'main';

    public function __construct(
        private AcceptInvitation $acceptInvitation,
        private PasswordHasher $passwordHasher,
        private UserProvider $userProvider,
        private Security $security,
    ) {
    }

    public function __invoke(#[MapRequestPayload] AcceptInvitationRequest $request): Response
    {
        $hashedPassword = HashedPassword::fromHash($this->passwordHasher->hash($request->password));

        $accepted = $this->acceptInvitation->accept($request->token, $hashedPassword);

        // Post-commit: authenticate the now-ACTIVE identity through the real firewall so LoginSuccessEvent
        // fires once — reusing the native id regeneration and the session-minting listener.
        $this->security->login(
            $this->userProvider->loadUserByIdentifier($accepted->email),
            firewallName: self::FIREWALL,
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
