<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\SelfUnlockForbidden;
use Erpify\Iam\Identity\Infrastructure\Http\SelfTargetedActRefusalAuditListener;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAuditDoubles;
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
#[CoversClass(SelfTargetedActRefusalAuditListener::class)]
final class SelfTargetedActRefusalAuditListenerTest extends TestCase
{
    use RequestBoundarySecurityAuditDoubles;

    private const string ACTOR_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    public function testRecordsASelfTargetedRefusalAsOneResourceLessSecurityEntry(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(
                'SELF_TARGETED_ACT_REFUSED',
                AuditLevel::SECURITY,
                // Resource-less: the target is the actor by definition, which `actor_id` already seals.
                null,
                // The problem type and the route, and nothing from the exception's context: its `userId` is the
                // actor's own id, and a second copy of it in `metadata` is one the actor-axis erasure never reads.
                ['refusal' => 'self-unlock-forbidden', 'route' => 'backoffice_user_unlock'],
            )
        ;

        $refusal = SelfUnlockForbidden::forActor(self::ACTOR_ID);
        $event = $this->event($refusal);

        $this->listener($logger)->onException($event);

        $this->assertSame($refusal, $event->getThrowable(), 'a recorded refusal is still answered as its 409');
        $this->assertFalse($event->hasResponse());
    }

    public function testIgnoresAConflictThatIsNotAimedAtTheActor(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->ignoringListener($logger)->onException(
            $this->event(LastActiveAdministratorProtected::forUser(self::ACTOR_ID)),
        );
    }

    public function testIgnoresAnyOtherFailure(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->ignoringListener($logger)->onException($this->event(new RuntimeException('boom')));
    }

    public function testIgnoresAnIdenticalRefusalRaisedOutsideTheApiPipeline(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->ignoringListener($logger)->onException(
            $this->event(SelfUnlockForbidden::forActor(self::ACTOR_ID), '/_profiler/0a1b'),
        );
    }

    public function testIgnoresSubRequests(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->ignoringListener($logger)->onException(new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/backoffice/users/' . self::ACTOR_ID . '/unlock', Request::METHOD_POST),
            HttpKernelInterface::SUB_REQUEST,
            SelfUnlockForbidden::forActor(self::ACTOR_ID),
        ));
    }

    public function testRecordsTheRefusalWithoutARouteRatherThanOmittingTheKey(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(
                'SELF_TARGETED_ACT_REFUSED',
                AuditLevel::SECURITY,
                null,
                ['refusal' => 'self-unlock-forbidden', 'route' => null],
            )
        ;

        $this->listener($logger)->onException($this->event(SelfUnlockForbidden::forActor(self::ACTOR_ID), route: null));
    }

    public function testHandsAFailedWriteToTheEventRatherThanLettingTheRefusalCompleteUnrecorded(): void
    {
        // Thrown from a `kernel.exception` listener the failure would escape HttpKernel with no Problem Details.
        $failure = new RuntimeException('audit store down');
        $logger = $this->createStub(AuditLogger::class);
        $logger->method('log')->willThrowException($failure);
        $event = $this->event(SelfUnlockForbidden::forActor(self::ACTOR_ID));

        $this->listener($logger)->onException($event);

        $this->assertWriteFailureOf($failure, $event->getThrowable());
        $this->assertFalse($event->hasResponse(), 'the responder, not this listener, answers the 5xx');
    }

    public function testHandsTheLeakedTransactionRefusalToTheEventChainedToTheRefusal(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');
        $refusal = SelfUnlockForbidden::forActor(self::ACTOR_ID);
        $event = $this->event($refusal);

        $this->listenerOverALeakedTransaction($logger)->onException($event);

        $this->assertRefusalOf($refusal, $event->getThrowable());
    }

    public function testRunsBeforeTheProblemDetailsResponder(): void
    {
        // ExceptionEvent extends RequestEvent, whose setResponse() stops propagation, so a listener ordered
        // after the responder would never see the throwable at all.
        $this->assertGreaterThan(ExceptionResponder::PRIORITY, SelfTargetedActRefusalAuditListener::PRIORITY);
    }

    private function listener(AuditLogger $logger): SelfTargetedActRefusalAuditListener
    {
        return new SelfTargetedActRefusalAuditListener($this->boundaryAudit($logger), new ApiRequestMatcher());
    }

    /**
     * A request this listener does not audit must not reach the audit connection at all.
     */
    private function ignoringListener(AuditLogger $logger): SelfTargetedActRefusalAuditListener
    {
        return new SelfTargetedActRefusalAuditListener($this->untouchedBoundaryAudit($logger), new ApiRequestMatcher());
    }

    private function listenerOverALeakedTransaction(AuditLogger $logger): SelfTargetedActRefusalAuditListener
    {
        return new SelfTargetedActRefusalAuditListener(
            $this->leakedTransactionBoundaryAudit($logger),
            new ApiRequestMatcher(),
        );
    }

    private function event(
        Throwable $throwable,
        string $path = '/api/v1/backoffice/users/' . self::ACTOR_ID . '/unlock',
        ?string $route = 'backoffice_user_unlock',
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
