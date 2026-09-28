<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\OverlongPasswordTimingListener;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;

/**
 * The hasher returns false for an over-long password without hashing, so a known address would answer it in no
 * time while an unknown one pays the floor. The refusal must therefore come before the identity is resolved —
 * the user loader here throws, so a listener that touched it would fail the test — and cost exactly one floor.
 *
 * @internal
 */
#[CoversClass(OverlongPasswordTimingListener::class)]
final class OverlongPasswordTimingListenerTest extends TestCase
{
    public function testRefusesAnOverlongPasswordWithOneFloorAndWithoutResolvingTheIdentity(): void
    {
        $floor = new CountingPreIdentityTimingFloor();
        $caught = null;

        try {
            (new OverlongPasswordTimingListener($floor))($this->event(
                \str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1),
            ));
        } catch (BadCredentialsException $badCredentialsException) {
            $caught = $badCredentialsException;
        }

        $this->assertInstanceOf(BadCredentialsException::class, $caught);
        $this->assertSame(1, $floor->invocations);
    }

    public function testLeavesAPasswordTheHasherWillVerifyToTheCredentialCheck(): void
    {
        $floor = new CountingPreIdentityTimingFloor();

        (new OverlongPasswordTimingListener($floor))($this->event(
            \str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH),
        ));

        $this->assertSame(0, $floor->invocations);
    }

    public function testRunsBeforeTheFirstListenerThatResolvesTheIdentity(): void
    {
        $subscribed = UserCheckerListener::getSubscribedEvents()[CheckPassportEvent::class] ?? null;
        $this->assertIsArray($subscribed);
        $priority = $subscribed[1] ?? null;
        $this->assertIsInt($priority);

        $this->assertGreaterThan($priority, OverlongPasswordTimingListener::PRIORITY);
    }

    private function event(string $password): CheckPassportEvent
    {
        $passport = new Passport(
            new UserBadge('someone@erpify.test', static function (): never {
                throw new LogicException('the identity must not be resolved before the length is refused');
            }),
            new PasswordCredentials($password),
        );

        return new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport);
    }
}
