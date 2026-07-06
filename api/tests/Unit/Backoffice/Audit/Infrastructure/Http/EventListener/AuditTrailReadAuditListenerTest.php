<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http\EventListener;

use Erpify\Backoffice\Audit\Infrastructure\Controller\AuditEventDetailController;
use Erpify\Backoffice\Audit\Infrastructure\Controller\AuditTimelineSearchController;
use Erpify\Backoffice\Audit\Infrastructure\Http\EventListener\AuditTrailReadAuditListener;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(AuditTrailReadAuditListener::class)]
final class AuditTrailReadAuditListenerTest extends TestCase
{
    private const string EVENT_ID = '0190e5e7-7ab0-7cde-8f01-aaaabbbbcccc';

    public function testRecordsASecurityEntryForAnAuthorizedTimelineRead(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(
                'AUDIT_TRAIL_READ',
                AuditLevel::SECURITY,
                null,
                ['route' => AuditTimelineSearchController::ROUTE_NAME],
            )
        ;

        $this->listener($logger)->onResponse(
            $this->event(AuditTimelineSearchController::ROUTE_NAME, path: '/api/v1/backoffice/audit/timeline'),
        );
    }

    public function testRecordsTheReadEventIdForAnAuthorizedDetailRead(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(
                'AUDIT_TRAIL_READ',
                AuditLevel::SECURITY,
                null,
                ['route' => AuditEventDetailController::ROUTE_NAME, 'auditEventId' => self::EVENT_ID],
            )
        ;

        $this->listener($logger)->onResponse($this->event(
            AuditEventDetailController::ROUTE_NAME,
            path: '/api/v1/backoffice/audit/events/' . self::EVENT_ID,
            id: self::EVENT_ID,
        ));
    }

    public function testIgnoresAReadOfAForeignRoute(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onResponse(
            $this->event('backoffice_bank_search', path: '/api/v1/backoffice/banks'),
        );
    }

    public function testIgnoresAnUnsuccessfulAuditResponse(): void
    {
        // A denied (403) or not-found (404) read never emits a read record: the denial is audited by the
        // sibling `ACCESS_DENIED` listener, and a 404 read nothing.
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onResponse($this->event(
            AuditTimelineSearchController::ROUTE_NAME,
            status: Response::HTTP_FORBIDDEN,
            path: '/api/v1/backoffice/audit/timeline',
        ));
    }

    public function testIgnoresASubRequest(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onResponse($this->event(
            AuditTimelineSearchController::ROUTE_NAME,
            path: '/api/v1/backoffice/audit/timeline',
            requestType: HttpKernelInterface::SUB_REQUEST,
        ));
    }

    private function listener(AuditLogger $logger): AuditTrailReadAuditListener
    {
        return new AuditTrailReadAuditListener($logger);
    }

    private function event(
        string $route,
        int $status = Response::HTTP_OK,
        string $path = '/api/v1/backoffice/audit/timeline',
        ?string $id = null,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        $request = Request::create($path);
        $request->attributes->set('_route', $route);

        if (null !== $id) {
            $request->attributes->set('id', $id);
        }

        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            new Response('', $status),
        );
    }
}
