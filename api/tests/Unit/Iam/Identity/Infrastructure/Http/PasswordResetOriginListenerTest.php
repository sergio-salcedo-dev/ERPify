<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\PasswordResetOriginListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(PasswordResetOriginListener::class)]
final class PasswordResetOriginListenerTest extends TestCase
{
    private const string ORIGIN = 'http://localhost';

    #[DataProvider('resetRoutes')]
    public function testAllowsASameOriginPostToEachResetRoute(string $route): void
    {
        $this->expectNotToPerformAssertions();

        (new PasswordResetOriginListener())($this->event($route, self::ORIGIN, HttpKernelInterface::MAIN_REQUEST));
    }

    #[DataProvider('resetRoutes')]
    public function testRejectsACrossOriginPostToEachResetRoute(string $route): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new PasswordResetOriginListener())(
            $this->event($route, 'https://evil.example', HttpKernelInterface::MAIN_REQUEST),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resetRoutes(): iterable
    {
        yield 'forgot' => ['identity_forgot_password'];
        yield 'reset' => ['identity_reset_password'];
    }

    public function testRejectsAResetPostThatCarriesNoOriginHeader(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new PasswordResetOriginListener())(
            $this->event('identity_reset_password', null, HttpKernelInterface::MAIN_REQUEST),
        );
    }

    public function testIgnoresAnyRouteOtherThanTheResetPair(): void
    {
        $this->expectNotToPerformAssertions();

        (new PasswordResetOriginListener())(
            $this->event('identity_login', 'https://evil.example', HttpKernelInterface::MAIN_REQUEST),
        );
    }

    public function testIgnoresSubRequests(): void
    {
        $this->expectNotToPerformAssertions();

        (new PasswordResetOriginListener())(
            $this->event('identity_reset_password', 'https://evil.example', HttpKernelInterface::SUB_REQUEST),
        );
    }

    private function event(string $route, ?string $origin, int $requestType): RequestEvent
    {
        $request = Request::create('http://localhost/api/v1/backoffice/reset-password', Request::METHOD_POST);
        $request->attributes->set('_route', $route);

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);
    }
}
