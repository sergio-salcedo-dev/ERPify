<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Infrastructure\Http\UploadProfilerGuard;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\ProfilerStorageInterface;
use Symfony\Component\Security\Http\Firewall;

/**
 * @internal
 */
#[CoversClass(UploadProfilerGuard::class)]
final class UploadProfilerGuardTest extends TestCase
{
    public function testTheProfilerIsDisabledForTheContentRoute(): void
    {
        $profiler = $this->profiler();

        (new UploadProfilerGuard($profiler))->onRequest($this->eventFor(UploadRoutes::CONTENT));

        $this->assertFalse($profiler->isEnabled());
    }

    public function testTheProfilerStaysOnForAnyOtherRoute(): void
    {
        $profiler = $this->profiler();

        (new UploadProfilerGuard($profiler))->onRequest($this->eventFor(UploadRoutes::DECLARE));

        $this->assertTrue($profiler->isEnabled());
    }

    /**
     * A request the firewall ends first (a 401 on the content route) must not be profiled with its query, so the
     * guard has to run before the firewall's request listener.
     */
    public function testTheGuardRunsBeforeTheFirewallListener(): void
    {
        $attributes = (new ReflectionMethod(UploadProfilerGuard::class, 'onRequest'))
            ->getAttributes(AsEventListener::class)
        ;
        $this->assertCount(1, $attributes);
        $guard = $attributes[0]->newInstance();
        $this->assertSame(KernelEvents::REQUEST, $guard->event);

        $firewall = Firewall::getSubscribedEvents()[KernelEvents::REQUEST] ?? null;
        $this->assertIsArray($firewall);
        $this->assertIsInt($firewall[1] ?? null, "the firewall's request listener declares a priority");
        $this->assertGreaterThan($firewall[1], $guard->priority);
    }

    private function profiler(): Profiler
    {
        return new Profiler($this->createStub(ProfilerStorageInterface::class));
    }

    private function eventFor(string $route): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
