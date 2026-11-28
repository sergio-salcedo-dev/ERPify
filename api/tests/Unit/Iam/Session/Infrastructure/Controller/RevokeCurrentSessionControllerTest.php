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
use Erpify\Tests\Double\Mercure\LegacyProtocolAuthorization;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;

/**
 * "Sign out this device" must be durable: a store outage on the revoke must not leave the cookie live and the
 * registry row ACTIVE (a session resumable once the store recovers). Even when the revoke throws, the controller
 * still invalidates the native session (dropping the cookie) and answers 204. The Mercure cookie clear is
 * best-effort on the same terms.
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

    public function testItExpiresTheMercureSubscriberCookie(): void
    {
        $store = $this->createStub(SessionRepository::class);
        $store->method('findActiveById')->willThrowException(SessionStoreUnavailable::storeUnreachable());

        $request = Request::create('/api/v1/sessions/revoke-current', Request::METHOD_POST);
        $request->setSession($this->createStub(SessionInterface::class));

        $response = ($this->controller($store))($request);

        $cookies = $this->mercureCookiesOf($response);

        $this->assertCount(1, $cookies);
        $this->assertNull($cookies[0]->getValue());
        $this->assertTrue($cookies[0]->isCleared(), 'the cookie must be expired so the browser drops it');
        $this->assertSame('/.well-known/mercure', $cookies[0]->getPath());
    }

    /**
     * The hub answers on its own public host, and `Authorization::createClearCookie()` throws when the request
     * arrives on a different second-level domain, because no cookie domain can reach both. That failure is the
     * one the controller swallows: the logout still drops the native session and answers 204, sets no Mercure
     * cookie at all, and leaves exactly one warning naming the cookie on the observability channel.
     */
    public function testSignOutStillSucceedsWhenTheMercureCookieCannotBeCleared(): void
    {
        $store = $this->createStub(SessionRepository::class);
        $store->method('findActiveById')->willThrowException(SessionStoreUnavailable::storeUnreachable());

        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->once())->method('invalidate');

        $request = Request::create('https://foreign.example/api/v1/sessions/revoke-current', Request::METHOD_POST);
        $request->setSession($nativeSession);

        $log = new TestHandler();
        $response = ($this->controller($store, new Logger('observability', [$log])))($request);

        $this->assertSame(self::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([], $this->mercureCookiesOf($response), 'a failed clear must not emit a half-built cookie');

        $records = \array_values(\array_filter(
            $log->getRecords(),
            static fn (LogRecord $record): bool => \str_contains($record->message, 'Mercure subscriber cookie'),
        ));

        $this->assertCount(1, $records, 'the swallowed failure must still be reported once');
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertInstanceOf(MercureRuntimeException::class, $records[0]->context['exception'] ?? null);
    }

    /**
     * @return list<Cookie>
     */
    private function mercureCookiesOf(Response $response): array
    {
        return \array_values(\array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'mercureAuthorization' === $cookie->getName(),
        ));
    }

    private function controller(
        SessionRepository $store,
        LoggerInterface $logger = new NullLogger(),
    ): RevokeCurrentSessionController {
        $currentSession = $this->createStub(CurrentSessionReference::class);
        $currentSession->method('get')->willReturn(SessionId::fromString(self::CURRENT_SESSION_ID));

        // The transaction body is never reached — the revoke throws on the read — so its collaborators are stubs.
        $revokeSession = new RevokeSession(
            $store,
            $this->createStub(EventBus::class),
            $this->createStub(TransactionManager::class),
        );

        return new RevokeCurrentSessionController(
            $currentSession,
            $revokeSession,
            LegacyProtocolAuthorization::create(),
            $logger,
        );
    }
}
