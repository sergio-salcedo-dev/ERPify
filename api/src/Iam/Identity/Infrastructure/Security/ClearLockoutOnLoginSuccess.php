<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Exception\LockoutStoreUnavailable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Clears the persisted lockout once an identity is admitted — the explicit reset that pairs with the lazy
 * "a lapsed lock reads as unlocked" rule, so a successful login always leaves the counter at zero.
 *
 * Priority sits ABOVE {@see SessionMintingSuccessListener} (@ -128), so clearing is a sibling of minting, not
 * folded into it. The registrar opens NO transaction when there is nothing to clear, so the common login has
 * no failure to reach here at all. On the rare path (a real lock, and its clear write fails) the shared unit
 * of work is closed by that failure, so minting could not complete on this request regardless — the DBAL fault
 * is re-classified to a retryable {@see LockoutStoreUnavailable} (503) rather than left to escape as a raw 500.
 * The catch is narrow: a genuine bug (a `TypeError`, an invalid transition) still surfaces loud. It reads only
 * the authenticated {@see SecurityUser}'s id and delegates to the idempotent {@see LoginAttemptRegistrar} — a
 * Symfony event adapter, hence Infrastructure.
 */
#[AsEventListener(event: LoginSuccessEvent::class, priority: self::PRIORITY)]
final readonly class ClearLockoutOnLoginSuccess
{
    public const int PRIORITY = -64;

    public function __construct(private LoginAttemptRegistrar $registrar)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();

        if (!$user instanceof SecurityUser) {
            return;
        }

        $userId = $user->id();

        if (null === $userId) {
            return;
        }

        try {
            $this->registrar->clear($userId);
        } catch (DbalException $dbalException) {
            throw LockoutStoreUnavailable::storeUnreachable($dbalException);
        }
    }
}
