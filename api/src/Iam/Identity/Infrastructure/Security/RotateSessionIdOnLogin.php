<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;

/**
 * Rotates the native session id on EVERY login of the `main` firewall, the same-identity one included.
 *
 * The firewall's own `SessionStrategyListener` (priority 0) skips its `migrate(true)` when the previous token
 * names the same user with the same token class. That is a `POST /login` from a device already signed in as
 * that user, and every flow that re-signs the caller's own device after a credential change; in each the id
 * the browser brought would go on carrying the new login, so a copy of it taken earlier — or fixed by an
 * attacker before the victim signed in again — survives the login meant to end it.
 *
 * It decides by mechanism rather than by mirroring the framework's condition: the id the request arrived with
 * is compared with the one the session holds now. Equal means nobody rotated, and the firewall's configured
 * strategy is applied here; different means the framework already did, and nothing is done twice. A request
 * that brought no session cookie runs on an id the server minted itself, which nobody else can know.
 *
 * Priority sits between the framework's listener and {@see SessionMintingSuccessListener}, so the id is final
 * before a registry row is written for it: a rotation that failed after the mint would strand an `ACTIVE` row.
 * It fails closed — a login reaching it with no session to rotate is a wiring fault, and is refused rather
 * than skipped.
 */
#[AsEventListener(event: LoginSuccessEvent::class, priority: self::PRIORITY)]
final readonly class RotateSessionIdOnLogin
{
    public const int PRIORITY = -96;

    public function __construct(
        #[Autowire(service: 'security.authentication.session_strategy.main')]
        private SessionAuthenticationStrategyInterface $sessionStrategy,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            throw new LogicException('A login of the main firewall reached the session rotation with no session.');
        }

        $session = $request->getSession();

        // No cookie, or a cookie naming another id: either the server minted this id itself or the framework
        // already rotated it.
        if ($request->cookies->get($session->getName()) !== $session->getId()) {
            return;
        }

        $this->sessionStrategy->onAuthentication($request, $event->getAuthenticatedToken());
    }
}
