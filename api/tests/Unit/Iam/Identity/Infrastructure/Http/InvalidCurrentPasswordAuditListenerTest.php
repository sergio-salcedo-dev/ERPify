<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Infrastructure\Controller\ChangeMyPasswordController;
use Erpify\Iam\Identity\Infrastructure\Http\InvalidCurrentPasswordAuditListener;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * @internal
 */
#[CoversClass(InvalidCurrentPasswordAuditListener::class)]
final class InvalidCurrentPasswordAuditListenerTest extends TestCase
{
    public function testRecordsARejectedCurrentPasswordAsASecurityEntrySealedWithTheRoute(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(
                'INVALID_CURRENT_PASSWORD',
                AuditLevel::SECURITY,
                // The third argument is the resource, and it must stay null: the only candidate is the
                // caller's own identity, which `actor_id` already seals.
                null,
                ['route' => ChangeMyPasswordController::ROUTE_NAME],
            )
        ;

        $this->listener($logger)->onException($this->event(new InvalidCurrentPassword()));
    }

    public function testIgnoresEveryOtherRefusalTheEndpointCanRaise(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onException($this->event(new RuntimeException('boom')));
    }

    public function testIgnoresAnIdenticalFailureRaisedOutsideTheApiPipeline(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->listener($logger)->onException($this->event(new InvalidCurrentPassword(), '/_profiler/0a1b'));
    }

    public function testIgnoresSubRequests(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $request = Request::create('/api/v1/me/password', Request::METHOD_POST);
        $request->attributes->set('_route', ChangeMyPasswordController::ROUTE_NAME);

        $this->listener($logger)->onException(new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new InvalidCurrentPassword(),
        ));
    }

    public function testRecordsTheRefusalWithoutARouteRatherThanOmittingTheKey(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with('INVALID_CURRENT_PASSWORD', AuditLevel::SECURITY, null, ['route' => null])
        ;

        $this->listener($logger)->onException($this->event(new InvalidCurrentPassword(), route: null));
    }

    public function testRunsBeforeTheProblemDetailsResponder(): void
    {
        // ExceptionEvent extends RequestEvent, whose setResponse() stops propagation, so a listener ordered
        // after the responder would never see the throwable at all.
        $this->assertGreaterThan(ExceptionResponder::PRIORITY, InvalidCurrentPasswordAuditListener::PRIORITY);
    }

    private function listener(AuditLogger $logger): InvalidCurrentPasswordAuditListener
    {
        return new InvalidCurrentPasswordAuditListener($logger, new ApiRequestMatcher());
    }

    private function event(
        Throwable $throwable,
        string $path = '/api/v1/me/password',
        ?string $route = ChangeMyPasswordController::ROUTE_NAME,
    ): ExceptionEvent {
        $request = Request::create($path, Request::METHOD_POST);

        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }

        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
