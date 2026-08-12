<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Iam\Identity\Infrastructure\Http\RecoveryThrottleAuditListener;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedRecoveryThrottleAuditBudget;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingLogger;
use Erpify\Tests\Unit\Iam\Identity\Application\ThrowingRecoveryThrottleAuditBudget;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drives the real recorder rather than a double: the listener's whole job is *when* the projection happens,
 * and a mocked recorder would assert the mock's schedule instead of the kernel's. That is what the collaborator
 * count buys, and why it is suppressed rather than trimmed.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RecoveryThrottleAuditListener::class)]
final class RecoveryThrottleAuditListenerTest extends TestCase
{
    private const string PATH = '/api/v1/backoffice/forgot-password';

    public function testARefusedRequestIsProjectedAfterTheResponse(): void
    {
        $auditLogger = new RecordingAuditLogger();

        $this->terminate($this->refusedRequest(UserMother::DEFAULT_EMAIL), $auditLogger);

        $this->assertCount(1, $auditLogger->records);
        $this->assertSame('PASSWORD_RECOVERY_THROTTLED', $auditLogger->records[0]['action']);
    }

    public function testAServedRequestCarriesNoAttributeAndIsNotProjected(): void
    {
        // The response of a served request is byte-identical to a refused one, so the attribute is the only
        // thing separating them. Without it the listener must stay silent rather than guess.
        $auditLogger = new RecordingAuditLogger();

        $this->terminate(Request::create(self::PATH, Request::METHOD_POST), $auditLogger);

        $this->assertSame([], $auditLogger->records);
    }

    public function testAnAttributeThatIsNotAnAddressIsIgnored(): void
    {
        $request = Request::create(self::PATH, Request::METHOD_POST);
        $request->attributes->set(RecoveryThrottleAuditListener::REFUSED_TARGET_ATTRIBUTE, true);

        $auditLogger = new RecordingAuditLogger();

        $this->terminate($request, $auditLogger);

        $this->assertSame([], $auditLogger->records);
    }

    public function testTheRequestIsReEstablishedForTheWriteAndReleasedAfterIt(): void
    {
        // Terminate runs after the kernel popped the request, so a write that did not push it back would
        // still produce a row — sealed with a `system` actor, a fresh correlation id and no ip or user-agent.
        // Nothing about the row's content can see that; only an observation taken during the write can.
        $requestStack = new RequestStack();
        $auditLogger = new RequestObservingAuditLogger($requestStack);

        $refused = $this->refusedRequest(UserMother::DEFAULT_EMAIL);

        $this->terminate($refused, $auditLogger, requestStack: $requestStack);

        $this->assertSame([self::PATH], $auditLogger->requestPathsAtWrite);
        $this->assertNotInstanceOf(
            Request::class,
            $requestStack->getCurrentRequest(),
            'The listener must leave the stack as it found it.',
        );
    }

    public function testAMisconfiguredBudgetNeitherEscapesNorUnbalancesTheStack(): void
    {
        $requestStack = new RequestStack();
        $listener = new RecoveryThrottleAuditListener(
            new RecordRecoveryThrottleAuditBestEffort(
                new ThrowingRecoveryThrottleAuditBudget(),
                new InMemoryUserRepository(UserMother::create()),
                new RecordingAuditLogger(),
                new RecordingLogger(),
            ),
            $requestStack,
        );

        // A limiter whose limit is below one rejects every reservation outright, so a misconfigured env var
        // reaches this path and nowhere else. The recorder swallows it; the listener's `finally` is what keeps
        // the stack balanced regardless of who breaks the contract above it.
        $listener->onTerminate(new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->refusedRequest(UserMother::DEFAULT_EMAIL),
            new Response(status: Response::HTTP_ACCEPTED),
        ));

        $this->assertNotInstanceOf(Request::class, $requestStack->getCurrentRequest());
    }

    public function testItListensOnTerminateAndNotEarlier(): void
    {
        // Listening one event earlier would put the write back on the wire and restore the timing
        // differential the deferral exists to remove — a change nothing else in the suite would notice.
        $attributes = (new ReflectionMethod(RecoveryThrottleAuditListener::class, 'onTerminate'))
            ->getAttributes(\Symfony\Component\EventDispatcher\Attribute\AsEventListener::class)
        ;

        $this->assertCount(1, $attributes);
        $this->assertSame(KernelEvents::TERMINATE, $attributes[0]->newInstance()->event);
    }

    private function refusedRequest(string $email): Request
    {
        $request = Request::create(self::PATH, Request::METHOD_POST);
        $request->attributes->set(RecoveryThrottleAuditListener::REFUSED_TARGET_ATTRIBUTE, $email);

        return $request;
    }

    private function terminate(
        Request $request,
        AuditLogger $auditLogger,
        ?RequestStack $requestStack = null,
    ): void {
        $listener = new RecoveryThrottleAuditListener(
            new RecordRecoveryThrottleAuditBestEffort(
                new FixedRecoveryThrottleAuditBudget(granted: true),
                new InMemoryUserRepository(UserMother::create()),
                $auditLogger,
                new RecordingLogger(),
            ),
            $requestStack ?? new RequestStack(),
        );

        $listener->onTerminate(new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            new Response(status: Response::HTTP_ACCEPTED),
        ));
    }
}
