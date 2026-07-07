<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\LoginOriginListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(LoginOriginListener::class)]
final class LoginOriginListenerTest extends TestCase
{
    private const string ORIGIN = 'http://localhost';

    public function testAllowsASameOriginLoginPost(): void
    {
        $this->expectNotToPerformAssertions();

        (new LoginOriginListener())($this->loginEvent(self::ORIGIN));
    }

    public function testRejectsACrossOriginLoginPost(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new LoginOriginListener())($this->loginEvent('https://evil.example'));
    }

    public function testRejectsALoginPostThatCarriesNoOriginHeader(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new LoginOriginListener())($this->loginEvent(null));
    }

    public function testIgnoresAnyRouteOtherThanTheLogin(): void
    {
        $this->expectNotToPerformAssertions();

        $event = $this->event('some_other_route', 'https://evil.example', HttpKernelInterface::MAIN_REQUEST);

        (new LoginOriginListener())($event);
    }

    public function testIgnoresSubRequests(): void
    {
        $this->expectNotToPerformAssertions();

        $event = $this->event('identity_login', 'https://evil.example', HttpKernelInterface::SUB_REQUEST);

        (new LoginOriginListener())($event);
    }

    private function loginEvent(?string $origin): RequestEvent
    {
        return $this->event('identity_login', $origin, HttpKernelInterface::MAIN_REQUEST);
    }

    private function event(string $route, ?string $origin, int $requestType): RequestEvent
    {
        $request = Request::create('http://localhost/api/v1/backoffice/login', Request::METHOD_POST);
        $request->attributes->set('_route', $route);

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);
    }
}
