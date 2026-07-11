<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Controller\RevokeCurrentSessionController;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * "Sign out this device" must be durable: a store outage on the revoke must not leave the cookie live and the
 * registry row ACTIVE (a session resumable once the store recovers). Even when the revoke throws, the controller
 * still invalidates the native session (dropping the cookie) and answers 204.
 *
 * @internal
 */
#[CoversClass(RevokeCurrentSessionController::class)]
final class RevokeCurrentSessionControllerTest extends TestCase
{
    private const string CURRENT_SESSION_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    private const int HTTP_NO_CONTENT = 204;

    public function testItDropsTheCookieAndAnswers204EvenWhenTheRevokeFails(): void
    {
        $store = $this->createStub(SessionRepository::class);
        $store->method('findActiveById')->willThrowException(SessionStoreUnavailable::storeUnreachable());

        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->once())->method('invalidate');

        $request = Request::create('/api/v1/sessions/revoke-current', Request::METHOD_POST);
        $request->setSession($nativeSession);

        $controller = $this->controller($store);

        $response = $controller($request);

        $this->assertSame(self::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }

    private function controller(SessionRepository $store): RevokeCurrentSessionController
    {
        $currentSession = $this->createStub(CurrentSessionReference::class);
        $currentSession->method('get')->willReturn(SessionId::fromString(self::CURRENT_SESSION_ID));

        // The transaction body is never reached — the revoke throws on the read — so its collaborators are stubs.
        $revokeSession = new RevokeSession(
            $store,
            $this->createStub(EventBus::class),
            $this->createStub(TransactionManager::class),
        );

        return new RevokeCurrentSessionController($currentSession, $revokeSession, new NullLogger());
    }
}
