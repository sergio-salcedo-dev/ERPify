<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuses a presented password longer than a hasher will verify, BEFORE the identity is loaded, paying the
 * timing floor once — so an over-long password costs the same whether or not the address exists.
 *
 * Without it the two branches split on existence by an entire KDF, deterministically and in one request: a
 * known address reaches `NativePasswordHasher::verify()`, which returns false for anything over
 * {@see PasswordHasherInterface::MAX_PASSWORD_LENGTH} bytes without hashing at all, while an unknown address pays
 * the {@see PreIdentityTimingFloor}'s full verification in the user provider. Refusing here, ahead of
 * `UserCheckerListener` (256) — the first listener that resolves the user — takes the provider out of the path
 * for both, and ahead of it only after `LoginThrottlingListener` (2080), so the refusal still counts against the
 * throttle like any other failed attempt.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: self::PRIORITY)]
final readonly class OverlongPasswordTimingListener
{
    public const int PRIORITY = 1024;

    public function __construct(private PreIdentityTimingFloor $timingFloor)
    {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $credentials = $passport->getBadge(PasswordCredentials::class);

        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }

        if (\strlen($credentials->getPassword()) <= PasswordHasherInterface::MAX_PASSWORD_LENGTH) {
            return;
        }

        $this->timingFloor->equalise();

        throw new BadCredentialsException('The presented password is invalid.');
    }
}
