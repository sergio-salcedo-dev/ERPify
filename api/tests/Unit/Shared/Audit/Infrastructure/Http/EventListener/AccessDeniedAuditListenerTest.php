<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http\EventListener;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessDeniedAuditListener;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(AccessDeniedAuditListener::class)]
final class AccessDeniedAuditListenerTest extends TestCase
{
    public function testRecordsASecurityEntryForADeniedApiRequest(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')->with('ACCESS_DENIED', AuditLevel::SECURITY);

        $this->listener($logger)->onException($this->event(new AccessDeniedException('Nope.')));
    }

    public function testWalksTheChainForAWrappedDenial(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')->with('ACCESS_DENIED', AuditLevel::SECURITY);

        $wrapped = new RuntimeException('boom', 0, new AccessDeniedException('Nope.'));

        $this->listener($logger)->onException($this->event($wrapped));
    }

    public function testIgnoresExceptionsThatAreNotADenial(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onException($this->event(new RuntimeException('boom')));
    }

    public function testIgnoresDenialsRaisedOutsideTheApiPipeline(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onException($this->event(new AccessDeniedException('Nope.'), '/_profiler/0a1b'));
    }

    public function testRunsBeforeTheProblemDetailsResponder(): void
    {
        // The denial must be recorded before the Problem Details responder sets the 403 and stops
        // propagation (RequestEvent::setResponse halts later listeners), so this listener's priority sits
        // above the responder's. The end-to-end wiring is exercised by the symfony_bridges Behat feature.
        $this->assertGreaterThan(ExceptionResponder::PRIORITY, AccessDeniedAuditListener::PRIORITY);
    }

    private function listener(AuditLogger $logger): AccessDeniedAuditListener
    {
        return new AccessDeniedAuditListener($logger, new ApiRequestMatcher());
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
