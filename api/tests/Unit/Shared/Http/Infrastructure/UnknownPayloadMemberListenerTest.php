<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Http\Infrastructure\UnknownPayloadMemberListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * The listener classifies rather than formats: it must leave the event carrying a throwable whose chain holds a
 * {@see ValidationFailedException}, because that — and only that — is what
 * {@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory} answers with the `validation-failed`
 * envelope. These tests pin that contract and the chain walk that finds a wrapped serializer failure.
 *
 * @internal
 */
#[CoversClass(UnknownPayloadMemberListener::class)]
final class UnknownPayloadMemberListenerTest extends TestCase
{
    #[Test]
    public function itRunsBeforeTheListenerThatBuildsTheProblemDetailsEnvelope(): void
    {
        // A lower priority would let the envelope be built from the unclassified serializer exception.
        $this->assertGreaterThan(ExceptionResponder::PRIORITY, UnknownPayloadMemberListener::PRIORITY);
    }

    #[Test]
    public function aSurplusMemberBecomesA422CarryingAViolationNamingIt(): void
    {
        $event = $this->eventFor(new ExtraAttributesException(['status']));

        (new UnknownPayloadMemberListener())($event);

        $replacement = $event->getThrowable();
        $this->assertInstanceOf(HttpException::class, $replacement);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $replacement->getStatusCode());

        $validation = $replacement->getPrevious();
        $this->assertInstanceOf(ValidationFailedException::class, $validation);
        $this->assertCount(1, $validation->getViolations());
        $this->assertSame('status', $validation->getViolations()->get(0)->getPropertyPath());
    }

    #[Test]
    public function everySurplusMemberIsNamed(): void
    {
        $event = $this->eventFor(new ExtraAttributesException(['status', 'id']));

        (new UnknownPayloadMemberListener())($event);

        $replacement = $event->getThrowable();
        $this->assertInstanceOf(HttpException::class, $replacement);
        $validation = $replacement->getPrevious();
        $this->assertInstanceOf(ValidationFailedException::class, $validation);
        $this->assertCount(2, $validation->getViolations());
    }

    #[Test]
    public function itFindsTheSerializerFailureThroughAWrappingException(): void
    {
        // Argument resolution may wrap the serializer failure before it reaches kernel.exception.
        $event = $this->eventFor(new RuntimeException('wrapped', 0, new ExtraAttributesException(['status'])));

        (new UnknownPayloadMemberListener())($event);

        $this->assertInstanceOf(HttpException::class, $event->getThrowable());
    }

    #[Test]
    public function anUnrelatedFailureIsLeftUntouched(): void
    {
        $unrelated = new RuntimeException('something else');
        $event = $this->eventFor($unrelated);

        (new UnknownPayloadMemberListener())($event);

        $this->assertSame($unrelated, $event->getThrowable());
    }

    private function eventFor(Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/backoffice/users/x/roles', Request::METHOD_PATCH),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
