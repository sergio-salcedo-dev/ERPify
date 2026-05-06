<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Infrastructure\Http\JsonApiErrorBuilder;
use InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Normalizes failures from search endpoints and request-payload mapping
 * into the `JsonApiErrorBuilder` document:
 *
 * - `NotEncodableValueException` (e.g. `#[MapRequestPayload]` receiving
 *   malformed JSON) → 422 with `"Invalid JSON body."`.
 * - `ValidationFailedException` (incl. when wrapped by Symfony's
 *   `#[MapRequestPayload]` / `#[MapQueryString]` resolvers into an
 *   `HttpException(422)`) → 422 with field-level violations.
 * - `InvalidArgumentException` raised under any route whose name ends
 *   with `_search` (e.g. an HMAC-valid but corrupted cursor reaching
 *   `PaginatorCursorFactory::createFromString`) → 400.
 *
 * Other exceptions are left for the framework's default handling.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 32)]
final readonly class SearchExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        if ($this->findInChain($throwable, NotEncodableValueException::class) instanceof NotEncodableValueException) {
            $event->setResponse(new JsonResponse(
                JsonApiErrorBuilder::fromErrors([
                    JsonApiErrorBuilder::error('', 'Invalid JSON body.'),
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ));

            return;
        }

        $validationException = $this->findInChain($throwable, ValidationFailedException::class);

        if ($validationException instanceof ValidationFailedException) {
            $event->setResponse(new JsonResponse(
                JsonApiErrorBuilder::fromViolations($validationException->getViolations()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ));

            return;
        }

        if ($throwable instanceof InvalidArgumentException && $this->isSearchRoute($request)) {
            $event->setResponse(new JsonResponse(
                JsonApiErrorBuilder::fromErrors([
                    JsonApiErrorBuilder::error('', $throwable->getMessage()),
                ]),
                Response::HTTP_BAD_REQUEST,
            ));
        }
    }

    /**
     * @template T of Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findInChain(?Throwable $throwable, string $class): ?Throwable
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    private function isSearchRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && \str_ends_with($route, '_search');
    }
}
