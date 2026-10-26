<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsFactory;
use Erpify\Shared\Http\Infrastructure\UnknownPayloadMemberListener;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\ExpectedClientError;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * The client-error half of {@see SentryEventFilter} ({@see ExpectedClientError}), held against the
 * error contract: what an HTTP request is answered with a 4xx never leaves the process, whichever
 * family raised it, and the same throwable outside a request still does.
 *
 * The coupling is the corpus itself — one throwable type per bridge the factory maps.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SentryEventFilter::class)]
#[CoversClass(ExpectedClientError::class)]
final class SentryEventFilterClientErrorTest extends TestCase
{
    #[DataProvider('provideDropsFrameworkClientErrorOnAnHttpRequestCases')]
    public function testDropsFrameworkClientErrorOnAnHttpRequest(Throwable $throwable): void
    {
        $this->assertNotInstanceOf(Event::class, $this->filter($this->httpEvent(), $throwable));
    }

    /**
     * The framework family of client errors: none implements a marker. The first three are the shapes
     * a production-mode stack has reported — a failed login, an anonymous `/me`, a cross-origin
     * recovery request.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function provideDropsFrameworkClientErrorOnAnHttpRequestCases(): iterable
    {
        yield 'failed login (401)' => [new CustomUserMessageAuthenticationException(
            'Invalid credentials.',
            previous: new BadCredentialsException('Bad credentials.'),
        )];
        yield 'anonymous request to a protected route (401/403)' => [new AccessDeniedException(
            'Access Denied. The user is not appropriately authenticated.',
        )];
        yield 'cross-origin recovery request (403)' => [
            new AccessDeniedHttpException('Cross-origin request rejected.'),
        ];
        yield 'insufficient authentication (401)' => [new InsufficientAuthenticationException()];
        yield 'validation failure (422)' => [new ValidationFailedException('value', new ConstraintViolationList())];
        yield 'unsupported media type (415)' => [new UnsupportedMediaTypeHttpException()];
        yield 'unknown payload member (422)' => [new ExtraAttributesException(['surplus'])];
    }

    #[DataProvider('provideKeepsAClientErrorTypeRaisedOutsideAnHttpRequestCases')]
    public function testKeepsAClientErrorTypeRaisedOutsideAnHttpRequest(Throwable $throwable): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, $this->filter($event, $throwable));
    }

    /**
     * Sentry's Messenger listener unwraps a `HandlerFailedException` and captures each nested throwable
     * top-level, so this is the exact shape a worker's failure arrives in: the type a client error
     * has, and no request.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function provideKeepsAClientErrorTypeRaisedOutsideAnHttpRequestCases(): iterable
    {
        yield 'validation failure in a handler' => [
            new ValidationFailedException('value', new ConstraintViolationList()),
        ];
        yield 'access denied in a handler' => [new AccessDeniedException()];
        yield 'domain client error in a handler' => [
            new class ('not-found', 'gone') extends DomainException implements NotFound {
            },
        ];
    }

    public function testKeepsALoginRefusedBecauseTheAuthenticationSystemFailed(): void
    {
        // The failure handler wraps every authentication failure in the same generic 401, so the
        // client cannot tell a provider fault from a wrong password — and neither can a filter that
        // reads only the top of the chain.
        $event = $this->httpEvent();
        $throwable = new CustomUserMessageAuthenticationException(
            'Invalid credentials.',
            previous: new AuthenticationServiceException('The user provider must return a UserInterface object.'),
        );

        $this->assertSame($event, $this->filter($event, $throwable));
    }

    public function testKeepsAnHttpExceptionCarryingAServerStatus(): void
    {
        $event = $this->httpEvent();

        $this->assertSame($event, $this->filter($event, new HttpException(502, 'upstream down')));
    }

    /**
     * The filter restates a decision the error contract owns. Running one corpus through both is what
     * stops the two drifting apart: a bridge the contract learns to answer 4xx without the filter
     * learning it reds here, and so does a filter that starts hiding a 5xx. The contract side is the
     * factory fed what it actually receives — Sentry captures at `kernel.exception` priority 128, and
     * `UnknownPayloadMemberListener` rewrites the throwable at 24 before `ExceptionResponder` (16).
     */
    #[DataProvider('provideDropsExactlyWhatTheErrorContractAnswersWithAClientStatusCases')]
    public function testDropsExactlyWhatTheErrorContractAnswersWithAClientStatus(Throwable $throwable): void
    {
        $exceptionEvent = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/probe', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
        new UnknownPayloadMemberListener()($exceptionEvent);
        $factory = new ProblemDetailsFactory('prod', new NullLogger());
        $status = $factory->fromThrowable($exceptionEvent->getThrowable(), 'correlation-id', '/api/v1/probe')->status;

        $dropped = !$this->filter($this->httpEvent(), $throwable) instanceof Event;

        $this->assertSame(
            $status >= 400 && $status < 500,
            $dropped,
            \sprintf('%s answers %d; the Sentry filter must drop it iff that is a 4xx.', $throwable::class, $status),
        );
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function provideDropsExactlyWhatTheErrorContractAnswersWithAClientStatusCases(): iterable
    {
        yield from self::provideDropsFrameworkClientErrorOnAnHttpRequestCases();
        yield 'domain client error (409)' => [
            new class ('conflict', 'in use') extends DomainException implements Conflict {
            },
        ];
        yield 'marker-less domain exception (500)' => [
            new class ('unexpected-state', 'corrupt') extends DomainException {
            },
        ];
        yield 'service unavailable marker (503)' => [
            new class ('down', 'store down') extends DomainException implements ServiceUnavailable {
            },
        ];
        yield 'marker on a non-domain throwable (500)' => [
            new class ('not a domain exception') extends RuntimeException implements NotFound {
            },
        ];
        yield 'http exception with a server status (502)' => [new HttpException(502, 'upstream down')];
        yield 'not found http exception (404)' => [new HttpException(404, 'no route')];
        yield 'plain runtime exception (500)' => [new RuntimeException('boom')];
    }

    private function httpEvent(): Event
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://erpify.test/api/v1/probe', 'method' => 'POST']);

        return $event;
    }

    private function filter(Event $event, Throwable $throwable): ?Event
    {
        return (new SentryEventFilter())($event, EventHint::fromArray(['exception' => $throwable]));
    }
}
