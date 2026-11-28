<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Infrastructure\Controller\RevokeCurrentSessionController;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Mercure\LegacyProtocolAuthorization;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;

/**
 * Clearing the Mercure subscriber cookie on sign-out is best-effort: a hub whose cookie cannot be scoped to the
 * request must not fail a logout. The hub answers on its own public host, and `Authorization::createClearCookie()`
 * throws when the request arrives on a different second-level domain, because no cookie domain reaches both — so
 * the real `Authorization` is driven into its failure rather than a double standing in for it.
 *
 * Kept apart from {@see RevokeCurrentSessionControllerTest}: this case needs a capturing logger and no session
 * registry failure, and the two concerns share nothing but the controller.
 *
 * @internal
 */
#[CoversClass(RevokeCurrentSessionController::class)]
final class RevokeCurrentSessionControllerMercureClearFailureTest extends TestCase
{
    private const int HTTP_NO_CONTENT = 204;

    public function testSignOutStillSucceedsWhenTheMercureCookieCannotBeCleared(): void
    {
        $nativeSession = $this->createMock(SessionInterface::class);
        $nativeSession->expects($this->once())->method('invalidate');

        $request = Request::create('https://foreign.example/api/v1/sessions/revoke-current', Request::METHOD_POST);
        $request->setSession($nativeSession);

        $log = new TestHandler();
        $response = ($this->controller(new Logger('observability', [$log])))($request);

        $this->assertSame(self::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([], $response->headers->getCookies(), 'a failed clear must not emit a half-built cookie');

        $records = $log->getRecords();
        $this->assertCount(1, $records, 'the swallowed failure must be reported exactly once');
        $this->assertTrue($log->hasWarningThatContains('Mercure subscriber cookie'));

        $record = \array_first($records);
        $this->assertInstanceOf(MercureRuntimeException::class, $record->context['exception'] ?? null);
    }

    private function controller(Logger $logger): RevokeCurrentSessionController
    {
        // No current session, so the revoke is never attempted and its collaborators are stubs.
        $currentSession = $this->createStub(CurrentSessionReference::class);
        $currentSession->method('get')->willReturn(null);

        return new RevokeCurrentSessionController(
            $currentSession,
            new RevokeSession(
                $this->createStub(SessionRepository::class),
                $this->createStub(EventBus::class),
                $this->createStub(TransactionManager::class),
            ),
            LegacyProtocolAuthorization::create(),
            $logger,
        );
    }
}
