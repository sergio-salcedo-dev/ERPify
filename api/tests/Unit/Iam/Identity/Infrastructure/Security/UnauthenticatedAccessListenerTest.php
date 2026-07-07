<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\UnauthenticatedAccessListener;
use Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessDeniedAuditListener;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(UnauthenticatedAccessListener::class)]
final class UnauthenticatedAccessListenerTest extends TestCase
{
    public function testRewritesAnAnonymousDenialToAnAuthenticationRequirement(): void
    {
        $event = $this->event(new AccessDeniedException('Nope.'));

        $this->listener(authenticated: false)->onException($event);

        $throwable = $event->getThrowable();
        $this->assertInstanceOf(InsufficientAuthenticationException::class, $throwable);
        $this->assertSame('Authentication required.', $throwable->getMessage());
        // No chained AccessDeniedException, so the audit listener's chain walk cannot re-discover the denial
        // and record an anonymous probe as a security event.
        $this->assertNotInstanceOf(Throwable::class, $throwable->getPrevious());
    }

    public function testWalksTheChainForAWrappedAnonymousDenial(): void
    {
        $event = $this->event(new RuntimeException('boom', 0, new AccessDeniedException('Nope.')));

        $this->listener(authenticated: false)->onException($event);

        $this->assertInstanceOf(InsufficientAuthenticationException::class, $event->getThrowable());
    }

    public function testLeavesAnAuthenticatedDenialUntouchedSoItStaysA403(): void
    {
        $denial = new AccessDeniedException('Nope.');
        $event = $this->event($denial);

        $this->listener(authenticated: true)->onException($event);

        $this->assertSame($denial, $event->getThrowable());
    }

    public function testIgnoresThrowablesThatAreNotADenial(): void
    {
        $other = new RuntimeException('boom');
        $event = $this->event($other);

        $this->listener(authenticated: false)->onException($event);

        $this->assertSame($other, $event->getThrowable());
    }

    public function testIgnoresDenialsRaisedOutsideTheApiPipeline(): void
    {
        $denial = new AccessDeniedException('Nope.');
        $event = $this->event($denial, '/_profiler/0a1b');

        $this->listener(authenticated: false)->onException($event);

        $this->assertSame($denial, $event->getThrowable());
    }

    public function testRunsAboveTheAuditListenerSoAnonymousHitsAreNotAudited(): void
    {
        // The rewrite must happen before AccessDeniedAuditListener (priority 32) reads the chain, so an
        // unauthenticated probe becomes an AuthenticationException it no longer records. Higher priority = 401
        // over a spuriously-audited 403.
        $this->assertGreaterThan(
            AccessDeniedAuditListener::PRIORITY,
            UnauthenticatedAccessListener::PRIORITY,
        );
    }

    private function listener(bool $authenticated): UnauthenticatedAccessListener
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $trustResolver = $this->createStub(AuthenticationTrustResolverInterface::class);
        $trustResolver->method('isFullFledged')->willReturn($authenticated);

        return new UnauthenticatedAccessListener($tokenStorage, $trustResolver, new ApiRequestMatcher());
    }

    private function event(Throwable $throwable, string $path = '/api/v1/backoffice/banks'): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
