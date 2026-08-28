<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\RecoveryOriginListener;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretController;
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
#[CoversClass(RecoveryOriginListener::class)]
final class RecoveryOriginListenerTest extends TestCase
{
    private const string ORIGIN = 'http://localhost';

    #[DataProvider('recoveryRoutes')]
    public function testAllowsASameOriginPostToEachRecoveryRoute(string $route): void
    {
        $this->expectNotToPerformAssertions();

        (new RecoveryOriginListener())($this->event($route, self::ORIGIN, HttpKernelInterface::MAIN_REQUEST));
    }

    #[DataProvider('recoveryRoutes')]
    public function testRejectsACrossOriginPostToEachRecoveryRoute(string $route): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new RecoveryOriginListener())(
            $this->event($route, 'https://evil.example', HttpKernelInterface::MAIN_REQUEST),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recoveryRoutes(): iterable
    {
        yield 'forgot' => ['identity_forgot_password'];
        yield 'reset' => ['identity_reset_password'];
        // The redemption is the newest member and the one with most to lose from being left out: it is an
        // anonymous POST that MINTS A SESSION, so a cross-site form reaching it would be forced login with a
        // stolen secret rather than a spammed mailbox.
        yield 'redeem' => [RedeemRecoverySecretController::ROUTE_NAME];
    }

    public function testRejectsAResetPostThatCarriesNoOriginHeader(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new RecoveryOriginListener())(
            $this->event('identity_reset_password', null, HttpKernelInterface::MAIN_REQUEST),
        );
    }

    public function testIgnoresAnyRouteOutsideTheRecoverySet(): void
    {
        $this->expectNotToPerformAssertions();

        (new RecoveryOriginListener())(
            $this->event('identity_login', 'https://evil.example', HttpKernelInterface::MAIN_REQUEST),
        );
    }

    public function testIgnoresSubRequests(): void
    {
        $this->expectNotToPerformAssertions();

        (new RecoveryOriginListener())(
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
