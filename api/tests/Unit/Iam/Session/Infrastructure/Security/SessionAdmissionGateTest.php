<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\SessionAdmissionGate;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The gate's admit-a-live-session happy path is exercised end-to-end by the Behat session feature (an
 * authenticated request reaches its controller); this unit covers the guards and the two throw outcomes.
 *
 * @internal
 */
#[CoversClass(SessionAdmissionGate::class)]
final class SessionAdmissionGateTest extends TestCase
{
    private const string CORRELATED_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

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

    private function apiRequestEvent(): RequestEvent
    {
        return $this->requestEvent(Request::create('/api/v1/me'));
    }

    private function requestEvent(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);
    }
}
