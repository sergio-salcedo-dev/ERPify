<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Organization\Membership\Application\FindUserOrganizationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Mints the server-side session once an identity is admitted. It listens on {@see LoginSuccessEvent} — which
 * the firewall dispatches only after the user checker's post-auth admission — so the session is minted
 * strictly post-admission, never for a rejected identity. This is the cross-sub-module orchestrator: it reads
 * the admission-time `organizationId` from the user's membership (the authoritative user↔org link) and hands
 * primitives to
 * {@see StartSession}, so the session subsystem stays unaware of `SecurityUser` and of `Organization`.
 *
 * Priority is below every built-in `LoginSuccessEvent` subscriber (the session-id `migrate` runs at 0), so the
 * `iamSessionId` written into the bag is set after the anti-fixation regeneration and survives it.
 * {@see LoginController} still answers 204 — minting neither couples to nor rewrites the login response.
 *
 * Fail-closed: if minting or the membership lookup throws, there is no live session, so the native cookie
 * is dropped explicitly (Symfony does not roll it back on its own) and the login answers the same 503 the gate
 * uses — "no `Session` row ⇒ no session".
 */
#[AsEventListener(event: LoginSuccessEvent::class, priority: self::PRIORITY)]
final readonly class SessionMintingSuccessListener
{
    public const int PRIORITY = -128;

    public function __construct(
        private StartSession $startSession,
        private FindUserOrganizationId $findUserOrganizationId,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof SecurityUser) {
            return;
        }

        $userId = $user->id();

        if (null === $userId) {
            return;
        }

        $request = $event->getRequest();

        try {
            $organizationId = $this->findUserOrganizationId->of($userId);
            $device = UserAgentDeviceLabel::fromUserAgent($request->headers->get('User-Agent'));

            $this->startSession->start($userId, $organizationId, $device, $request->getClientIp());
        } catch (Throwable $throwable) {
            $request->getSession()->invalidate();

            throw SessionStoreUnavailable::storeUnreachable($throwable);
        }
    }
}
