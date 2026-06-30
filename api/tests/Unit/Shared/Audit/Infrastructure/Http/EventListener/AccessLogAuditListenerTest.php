<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http\EventListener;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditPolicy;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessLogAuditListener;
use Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(AccessLogAuditListener::class)]
final class AccessLogAuditListenerTest extends TestCase
{
    public function testAuditsASuccessfulApiReadThroughTheSeamWithinTheRequestScope(): void
    {
        $request = $this->request('/api/v1/backoffice/banks', 'GET', 'backoffice_bank_search');
        $requestStack = new RequestStack();

        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('ROUTE_BACKOFFICE_BANK_SEARCH', AuditLevel::ACTIVITY, null)
            ->willReturnCallback(function () use ($request, $requestStack): void {
                $this->assertSame(
                    $request,
                    $requestStack->getCurrentRequest(),
                    'the request is re-established so the seal runs in request scope',
                );
            })
        ;

        $this->listener($logger, $requestStack)->onTerminate($this->terminateEvent($request, 200));

        $restoredStack = $requestStack->getCurrentRequest();
        $this->assertNotInstanceOf(Request::class, $restoredStack, 'request stack restored after emit');
    }

    public function testSealsTheResourceTheRouteDeclaresForARecordScopedRead(): void
    {
        $resourceId = '0190f5d1-2222-7000-8000-000000000001';
        $request = $this->request('/api/v1/backoffice/banks/' . $resourceId, 'GET', 'backoffice_bank_get');
        $request->attributes->set('_audit_resource_type', 'Bank');
        $request->attributes->set('id', $resourceId);

        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('ROUTE_BACKOFFICE_BANK_GET', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId))
        ;

        $this->listener($logger)->onTerminate($this->terminateEvent($request, 200));
    }

    public function testIgnoresNonApiPaths(): void
    {
        $logger = $this->loggerExpectingNoEmission();
        $request = $this->request('/_wdt/0a1b2c', 'GET', 'web_profiler_toolbar');

        $this->listener($logger)->onTerminate($this->terminateEvent($request, 200));
    }

    public function testIgnoresNonSuccessfulResponses(): void
    {
        $logger = $this->loggerExpectingNoEmission();
        $request = $this->request('/api/v1/backoffice/banks', 'GET', 'backoffice_bank_search');

        $this->listener($logger)->onTerminate($this->terminateEvent($request, 500));
    }

    public function testIgnoresWritesThePolicyDeclinesEvenWhenSuccessful(): void
    {
        $logger = $this->loggerExpectingNoEmission();
        $request = $this->request('/api/v1/backoffice/banks', 'POST', 'backoffice_bank_create');

        $this->listener($logger)->onTerminate($this->terminateEvent($request, 201));
    }

    public function testIgnoresRoutesWithACanonicalExplicitAudit(): void
    {
        $logger = $this->loggerExpectingNoEmission();
        $request = $this->request(
            '/api/v1/backoffice/banks/0190f5d1-2222-7000-8000-000000000001/accounts',
            'GET',
            'backoffice_bank_account_search',
        );
        // The route declares its own canonical audit; Symfony's router would surface that as this
        // internal request attribute (here set directly, as the unit test has no real router).
        $request->attributes->set('_audit_canonical', true);

        $this->listener($logger)->onTerminate($this->terminateEvent($request, 200));
    }

    private function listener(AuditLogger $logger, ?RequestStack $requestStack = null): AccessLogAuditListener
    {
        return new AccessLogAuditListener(
            new AuditPolicy(),
            $logger,
            $requestStack ?? new RequestStack(),
            new ApiRequestMatcher(),
            new RequestAuditResourceExtractor(),
        );
    }

    private function loggerExpectingNoEmission(): AuditLogger
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        return $logger;
    }

    private function request(string $path, string $method, string $routeName): Request
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', $routeName);

        return $request;
    }

    private function terminateEvent(Request $request, int $status): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response('', $status));
    }
}
