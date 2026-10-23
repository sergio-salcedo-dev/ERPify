<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\RotateSessionIdOnLogin;
use Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategy;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;

/**
 * One case per branch: the id a login leaves in place is rotated, one the firewall already rotated is not
 * rotated again, a fresh server-minted session has nothing to rotate, another firewall is not this one's to
 * judge, and a login with no session at all is refused rather than waved through.
 *
 * @internal
 */
#[CoversClass(RotateSessionIdOnLogin::class)]
final class RotateSessionIdOnLoginTest extends TestCase
{
    private const string BROUGHT_ID = 'the-id-the-browser-brought';

    public function testAnIdTheLoginLeftInPlaceIsRotatedKeepingWhatTheSessionCarries(): void
    {
        $request = $this->requestBringing(self::BROUGHT_ID);

        $this->listener(new SessionAuthenticationStrategy(SessionAuthenticationStrategy::MIGRATE))(
            $this->loginSuccess($request),
        );

        $this->assertNotSame(self::BROUGHT_ID, $request->getSession()->getId());
        $this->assertSame('kept', $request->getSession()->get('attribute'));
    }

    /**
     * The native storage reports no id until the session starts, and nothing guarantees a login started it;
     * compared unstarted, the brought id would read as already rotated and survive the login.
     */
    public function testASessionNotYetStartedIsStartedBeforeItsIdIsJudged(): void
    {
        $session = new Session(new class extends MockArraySessionStorage {
            #[Override]
            public function getId(): string
            {
                return $this->started ? parent::getId() : '';
            }
        });
        $session->setId(self::BROUGHT_ID);

        $request = new Request();
        $request->setSession($session);
        $request->cookies->set($session->getName(), self::BROUGHT_ID);

        $this->listener(new SessionAuthenticationStrategy(SessionAuthenticationStrategy::MIGRATE))(
            $this->loginSuccess($request),
        );

        $this->assertTrue($session->isStarted());
        $this->assertNotSame(self::BROUGHT_ID, $session->getId());
    }

    public function testAnIdTheFirewallAlreadyRotatedIsNotRotatedTwice(): void
    {
        $request = $this->requestBringing(self::BROUGHT_ID);
        $request->getSession()->migrate(true);

        $this->listener($this->strategyNeverCalled())($this->loginSuccess($request));
    }

    /** No cookie came in, so the id is the server's own and nobody else can hold it. */
    public function testAFreshServerMintedSessionHasNothingToRotate(): void
    {
        $request = $this->requestBringing(null);

        $this->listener($this->strategyNeverCalled())($this->loginSuccess($request));
    }

    public function testALoginOfAnotherFirewallIsLeftToThatFirewall(): void
    {
        $request = $this->requestBringing(self::BROUGHT_ID);

        $this->listener($this->strategyNeverCalled())($this->loginSuccess($request, firewall: 'other'));
    }

    public function testALoginWithNoSessionIsRefusedRatherThanSkipped(): void
    {
        $this->expectException(LogicException::class);

        $this->listener($this->strategyNeverCalled())($this->loginSuccess(new Request()));
    }

    /**
     * After the framework's own listener (0), so a rotation it performed is seen and not repeated; before the
     * minting listener, so the registry row is written for the final id and a failed rotation strands none.
     */
    public function testRunsAfterTheFirewallsOwnRotationAndBeforeTheMinting(): void
    {
        $rotation = $this->priorityOf(RotateSessionIdOnLogin::class);

        $this->assertLessThan(0, $rotation);
        $this->assertGreaterThan($this->priorityOf(SessionMintingSuccessListener::class), $rotation);
    }

    /**
     * @param class-string $listener
     */
    private function priorityOf(string $listener): int
    {
        $attributes = (new ReflectionClass($listener))->getAttributes(AsEventListener::class);
        $this->assertCount(1, $attributes);

        return $attributes[0]->newInstance()->priority;
    }

    private function requestBringing(?string $cookieId): Request
    {
        $session = new Session(new MockArraySessionStorage());

        if (null !== $cookieId) {
            $session->setId($cookieId);
        }

        $session->start();
        $session->set('attribute', 'kept');

        $request = new Request();
        $request->setSession($session);

        if (null !== $cookieId) {
            $request->cookies->set($session->getName(), $cookieId);
        }

        return $request;
    }

    private function strategyNeverCalled(): SessionAuthenticationStrategyInterface
    {
        $strategy = $this->createMock(SessionAuthenticationStrategyInterface::class);
        $strategy->expects($this->never())->method('onAuthentication');

        return $strategy;
    }

    private function listener(SessionAuthenticationStrategyInterface $strategy): RotateSessionIdOnLogin
    {
        return new RotateSessionIdOnLogin($strategy);
    }

    private function loginSuccess(Request $request, string $firewall = 'main'): LoginSuccessEvent
    {
        $event = $this->createStub(LoginSuccessEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getFirewallName')->willReturn($firewall);
        $event->method('getAuthenticatedToken')->willReturn($this->createStub(TokenInterface::class));

        return $event;
    }
}
