<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsFactory;
use Erpify\Shared\Http\Infrastructure\UnknownPayloadMemberListener;
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
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * The client-error half of {@see SentryEventFilter}, held against the error contract: only a domain
 * `ClientError` on an HTTP request is dropped, the framework's own 4xx are reported, and nothing the
 * contract answers with a non-4xx status is ever hidden.
 *
 * The coupling is the corpus itself — one throwable type per bridge the factory maps.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SentryEventFilter::class)]
final class SentryEventFilterClientErrorTest extends TestCase
{
    public function testDropsADomainClientErrorOnAnHttpRequest(): void
    {
        $this->assertNotInstanceOf(Event::class, $this->filter($this->httpEvent(), self::domainNotFound()));
    }

    #[DataProvider('provideReportsFrameworkClientErrorOnAnHttpRequestCases')]
    public function testReportsFrameworkClientErrorOnAnHttpRequest(Throwable $throwable): void
    {
        $event = $this->httpEvent();

        $this->assertSame($event, $this->filter($event, $throwable));
    }

    /**
     * The framework family of client errors implements no marker, and a spike of any of them is a
     * signal — an attack, a misconfigured voter, a client regression. The first three are the shapes a
     * production-mode stack has reported: a failed login, an anonymous `/me`, a cross-origin recovery
     * request.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function provideReportsFrameworkClientErrorOnAnHttpRequestCases(): iterable
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

    public function testKeepsADomainClientErrorRaisedOutsideAnHttpRequest(): void
    {
        // Sentry's Messenger listener unwraps a `HandlerFailedException` and captures each nested
        // throwable top-level, so this is the exact shape a worker's failure arrives in.
        $event = Event::createEvent();

        $this->assertSame($event, $this->filter($event, self::domainNotFound()));
    }

    public function testKeepsAMarkerOnANonDomainThrowable(): void
    {
        $event = $this->httpEvent();
        $throwable = new class ('not a domain exception') extends RuntimeException implements NotFound {
        };

        $this->assertSame($event, $this->filter($event, $throwable));
    }

    /**
     * The filter restates a decision the error contract owns, so one corpus runs through both: whatever
     * the filter drops must be answered with a 4xx. A filter that starts hiding a 5xx reds here. The
     * contract side is the factory fed what it actually receives — Sentry captures at `kernel.exception`
     * priority 128, and `UnknownPayloadMemberListener` rewrites the throwable at 24 before
     * `ExceptionResponder` (16).
     */
    #[DataProvider('provideNeverDropsWhatTheErrorContractAnswersWithANonClientStatusCases')]
    public function testNeverDropsWhatTheErrorContractAnswersWithANonClientStatus(Throwable $throwable): void
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

        $this->assertTrue(
            !$dropped || ($status >= 400 && $status < 500),
            \sprintf('%s answers %d and the Sentry filter drops it.', $throwable::class, $status),
        );
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function provideNeverDropsWhatTheErrorContractAnswersWithANonClientStatusCases(): iterable
    {
        yield from self::provideReportsFrameworkClientErrorOnAnHttpRequestCases();
        yield 'domain client error (404)' => [self::domainNotFound()];
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
        yield 'client marker beside service unavailable (503)' => [
            new class ('down', 'store down') extends DomainException implements ServiceUnavailable, NotFound {
                public const string TYPE = 'store-down';
            },
        ];
        yield 'marker on a non-domain throwable (500)' => [
            new class ('not a domain exception') extends RuntimeException implements NotFound {
            },
        ];
        yield 'http exception with a server status (502)' => [new HttpException(502, 'upstream down')];
        yield 'plain runtime exception (500)' => [new RuntimeException('boom')];
    }

    private static function domainNotFound(): DomainException
    {
        return new class ('not-found', 'gone') extends DomainException implements NotFound {
        };
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
