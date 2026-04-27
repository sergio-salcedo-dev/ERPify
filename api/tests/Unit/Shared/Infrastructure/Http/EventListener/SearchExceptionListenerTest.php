<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Infrastructure\Http\EventListener\SearchExceptionListener;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(SearchExceptionListener::class)]
final class SearchExceptionListenerTest extends TestCase
{
    public function testValidationFailedExceptionMapsTo422WithViolationEnvelope(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('Must be positive.', null, [], null, 'page', -1),
            new ConstraintViolation('Bad UUID.', null, [], null, 'ids[0]', 'invalid'),
        ]);
        $exceptionEvent = $this->buildEvent(
            new ValidationFailedException(null, $constraintViolationList),
            'backoffice_bank_search',
        );

        (new SearchExceptionListener())($exceptionEvent);

        $response = $exceptionEvent->getResponse();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{errors: list<array{source: array{parameter: string}, title: string}>} $payload */
        $payload = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $payload['errors']);
        $this->assertSame('page', $payload['errors'][0]['source']['parameter']);
        $this->assertSame('Must be positive.', $payload['errors'][0]['title']);
        $this->assertSame('ids[0]', $payload['errors'][1]['source']['parameter']);
    }

    public function testValidationFailedExceptionWrappedAsPreviousIsAlsoRecognised(): void
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation('bad', null, [], null, 'limit', 0),
        ]);
        $runtimeException = new RuntimeException('wrapper', 0, new ValidationFailedException(null, $constraintViolationList));
        $exceptionEvent = $this->buildEvent($runtimeException, 'backoffice_bank_search');

        (new SearchExceptionListener())($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testInvalidArgumentExceptionOnSearchRouteMapsTo400(): void
    {
        $exceptionEvent = $this->buildEvent(
            new InvalidArgumentException('Cursor body is not valid base64.'),
            'backoffice_bank_search',
        );

        (new SearchExceptionListener())($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{errors: non-empty-list<array{title: string}>} $payload */
        $payload = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Cursor body is not valid base64.', $payload['errors'][0]['title']);
    }

    public function testInvalidArgumentExceptionOnNonSearchRouteIsLeftAlone(): void
    {
        $exceptionEvent = $this->buildEvent(
            new InvalidArgumentException('unrelated'),
            'backoffice_bank_post',
        );

        (new SearchExceptionListener())($exceptionEvent);

        $this->assertNotInstanceOf(Response::class, $exceptionEvent->getResponse());
    }

    public function testUnrelatedExceptionIsLeftAlone(): void
    {
        $exceptionEvent = $this->buildEvent(
            new RuntimeException('unrelated'),
            'backoffice_bank_search',
        );

        (new SearchExceptionListener())($exceptionEvent);

        $this->assertNotInstanceOf(Response::class, $exceptionEvent->getResponse());
    }

    private function buildEvent(Throwable $throwable, string $routeName): ExceptionEvent
    {
        $request = Request::create('/whatever');
        $request->attributes->set('_route', $routeName);

        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
