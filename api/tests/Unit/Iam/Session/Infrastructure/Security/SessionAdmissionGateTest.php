<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\SessionAdmissionGate;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Both outcomes of the gate, plus the guards that keep it off the requests it must not judge.
 *
 * The admit side is asserted HERE and not left to the Behat session feature, because a suite in which no test
 * admits cannot tell an admitting gate from a refusing one: measured on this file before the admit cases
 * existed, a gate rewritten to refuse EVERY session — the row loaded and then thrown away — passed all six
 * tests, 3 assertions, exit 0. The two consequences of admitting are pinned apart: the request continues with
 * its native session intact, and the loaded row is published for the controllers that would otherwise read it
 * a second time.
 *
 * The coupling suppression below is measured rather than inherited: at 15 it is the arithmetic of standing a
 * security gate up inside a request, not a method anyone would want simpler. Nine of the fifteen are
 * structural — the gate's five constructor collaborators, plus the Request, RequestEvent, kernel and native
 * session needed to drive one through it — and the rest are the entity and the two exceptions the outcomes
 * are asserted against. Splitting the class by outcome was considered and rejected: every half needs the same
 * construction, so it would copy the nine into a second file and lower neither.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SessionAdmissionGate::class)]
final class SessionAdmissionGateTest extends TestCase
{
    private const string CORRELATED_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    public function testItAdmitsALiveSession(): void
    {
        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->never())->method('invalidate');
        $store = $this->storeHolding(SessionMother::active());
        $gate = $this->gate($this->correlation(self::CORRELATED_ID), $store, fullFledged: true);

        $gate($this->apiRequestEvent($nativeSession));
    }

    public function testItPublishesTheAdmittedSessionOnTheRequest(): void
    {
        $session = SessionMother::active();
        $store = $this->storeHolding($session);
        $gate = $this->gate($this->correlation(self::CORRELATED_ID), $store, fullFledged: true);
        $event = $this->apiRequestEvent();

        $gate($event);

        $this->assertSame($session, SessionAdmissionGate::admittedSession($event->getRequest()));
    }

    public function testItForcesReloginWhenThereIsNoCorrelation(): void
    {
        $gate = $this->gate($this->correlation(null), $this->createStub(SessionRepository::class), fullFledged: true);

        $this->expectException(SessionNoLongerActive::class);

        $gate($this->apiRequestEvent());
    }

    public function testItForcesReloginWhenTheSessionIsNoLongerActive(): void
    {
        // The correlation points at a session the store no longer returns as active (revoked or expired).
        $sessions = $this->createStub(SessionRepository::class);
        $gate = $this->gate($this->correlation(self::CORRELATED_ID), $sessions, fullFledged: true);

        $this->expectException(SessionNoLongerActive::class);

        $gate($this->apiRequestEvent());
    }

    /**
     * A refused request must not leave a full-fledged cookie behind: it would arrive authenticated again and
     * spend another registry round-trip on every request, `PUBLIC_ACCESS` routes included, where an anonymous
     * caller costs none.
     */
    public function testItDropsTheNativeSessionWhenTheSessionIsNoLongerActive(): void
    {
        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->once())->method('invalidate');
        $gate = $this->gate(
            $this->correlation(self::CORRELATED_ID),
            $this->createStub(SessionRepository::class),
            fullFledged: true,
        );

        $this->expectException(SessionNoLongerActive::class);

        $gate($this->apiRequestEvent($nativeSession));
    }

    public function testItDropsTheNativeSessionWhenThereIsNoCorrelation(): void
    {
        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->once())->method('invalidate');
        $gate = $this->gate($this->correlation(null), $this->createStub(SessionRepository::class), fullFledged: true);

        $this->expectException(SessionNoLongerActive::class);

        $gate($this->apiRequestEvent($nativeSession));
    }

    public function testItFailsClosedWhenTheStoreIsUnavailable(): void
    {
        $sessions = $this->createStub(SessionRepository::class);
        $sessions->method('findActiveById')->willThrowException(SessionStoreUnavailable::storeUnreachable());
        $gate = $this->gate($this->correlation(self::CORRELATED_ID), $sessions, fullFledged: true);

        $this->expectException(SessionStoreUnavailable::class);

        $gate($this->apiRequestEvent());
    }

    public function testItIgnoresANonApiRequest(): void
    {
        $gate = $this->gate($this->correlation(null), $this->createStub(SessionRepository::class), fullFledged: true);

        $gate($this->requestEvent(Request::create('/dashboard')));

        $this->expectNotToPerformAssertions();
    }

    public function testItIgnoresAnAnonymousRequestToAPublicRoute(): void
    {
        $gate = $this->gate($this->correlation(null), $this->createStub(SessionRepository::class), fullFledged: false);

        $gate($this->apiRequestEvent());

        $this->expectNotToPerformAssertions();
    }

    public function testItIgnoresSubRequests(): void
    {
        $gate = $this->gate($this->correlation(null), $this->createStub(SessionRepository::class), fullFledged: true);

        $gate($this->requestEvent(Request::create('/api/v1/me'), HttpKernelInterface::SUB_REQUEST));

        $this->expectNotToPerformAssertions();
    }

    private function storeHolding(Session $session): SessionRepository
    {
        $sessions = $this->createStub(SessionRepository::class);
        $sessions->method('findActiveById')->willReturn($session);

        return $sessions;
    }

    private function correlation(?string $sessionId): CurrentSessionReference
    {
        $currentSession = $this->createStub(CurrentSessionReference::class);
        $currentSession->method('get')->willReturn(null === $sessionId ? null : SessionId::fromString($sessionId));

        return $currentSession;
    }

    private function gate(
        CurrentSessionReference $currentSession,
        SessionRepository $sessions,
        bool $fullFledged,
    ): SessionAdmissionGate {
        $trustResolver = $this->createStub(AuthenticationTrustResolverInterface::class);
        $trustResolver->method('isFullFledged')->willReturn($fullFledged);

        return new SessionAdmissionGate(
            $currentSession,
            $sessions,
            $this->createStub(TokenStorageInterface::class),
            $trustResolver,
            new ApiRequestMatcher(),
        );
    }

    private function apiRequestEvent(?SessionInterface $nativeSession = null): RequestEvent
    {
        return $this->requestEvent(Request::create('/api/v1/me'), nativeSession: $nativeSession);
    }

    private function requestEvent(
        Request $request,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        ?SessionInterface $nativeSession = null,
    ): RequestEvent {
        if ($nativeSession instanceof SessionInterface) {
            $request->setSession($nativeSession);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);
    }
}
