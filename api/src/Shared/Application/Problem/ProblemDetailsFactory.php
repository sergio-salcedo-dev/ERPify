<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\Forbidden;
use Erpify\Shared\Domain\Exception\InvalidInput;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Domain\Exception\RateLimited;
use Erpify\Shared\Domain\Exception\Unauthenticated;
use JsonSerializable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Single mapping site between domain throwables and the {@see ProblemDetails} wire shape.
 *
 * Marker resolution honours the implements-clause order pinned by Story 1.1's
 * `testMarkerOrderingFollowsImplementsClause`; the constant `MARKER_STATUS_MAP` is the
 * sole source of truth for the marker→HTTP-status mapping (NFR25).
 *
 * Marked `final` (not `final readonly`) so Stories 3.2 / 3.3 can override the
 * `redactKeys` / `applyUnserializableSentinel` seams.
 */
final class ProblemDetailsFactory
{
    private const array MARKER_STATUS_MAP = [
        NotFound::class => 404,
        Conflict::class => 409,
        Forbidden::class => 403,
        Unauthenticated::class => 401,
        InvariantViolation::class => 422,
        InvalidInput::class => 400,
        RateLimited::class => 429,
    ];

    private const array MARKER_DEFAULT_TYPE_MAP = [
        NotFound::class => 'not-found',
        Conflict::class => 'conflict',
        Forbidden::class => 'forbidden',
        Unauthenticated::class => 'unauthenticated',
        InvariantViolation::class => 'invariant-violation',
        InvalidInput::class => 'invalid-input',
        RateLimited::class => 'rate-limited',
    ];

    /**
     * Story 1.5 — status→type alignment for Symfony framework exceptions. Values mirror
     * `MARKER_DEFAULT_TYPE_MAP` for the corresponding statuses so PWA `type`-only routing (FR44)
     * is uniform whether the error originated from a marker `DomainException`, a Security Core
     * exception, or a Symfony `HttpExceptionInterface`. The alignment is pinned by
     * `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues`.
     */
    private const array HTTP_STATUS_TYPE_MAP = [
        400 => 'invalid-input',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not-found',
        409 => 'conflict',
        422 => 'invariant-violation',
        429 => 'rate-limited',
    ];

    private const array RESERVED_KEYS = ['type', 'title', 'status', 'detail', 'instance', 'correlation-id', 'violations'];

    public function fromThrowable(Throwable $e, string $correlationId, string $instance): ProblemDetails
    {
        if ($e instanceof DomainException) {
            $firstMarker = $this->firstMatchingMarker($e);

            $status = null !== $firstMarker ? self::MARKER_STATUS_MAP[$firstMarker] : 500;

            $explicitType = $e->type();

            if ('' !== $explicitType) {
                $type = $explicitType;
            } elseif (null !== $firstMarker) {
                $type = self::MARKER_DEFAULT_TYPE_MAP[$firstMarker];
            } else {
                $type = 'domain-error';
            }

            return new ProblemDetails(
                type: $type,
                title: $e->title(),
                status: $status,
                detail: null,
                instance: $instance,
                correlationId: $correlationId,
                extensions: $this->buildExtensions($e),
            );
        }

        $validationException = $this->findInChain($e, ValidationFailedException::class);

        if ($validationException instanceof Throwable) {
            return new ProblemDetails(
                type: 'validation-failed',
                title: 'Validation failed.',
                status: 422,
                detail: null,
                instance: $instance,
                correlationId: $correlationId,
                extensions: ['violations' => $this->buildViolations($validationException->getViolations())],
            );
        }

        if ($e instanceof AccessDeniedException) {
            return $this->buildBridgeResponse(
                type: 'forbidden',
                status: 403,
                title: '' !== $e->getMessage() ? $e->getMessage() : 'Access denied.',
                correlationId: $correlationId,
                instance: $instance,
            );
        }

        if ($e instanceof AuthenticationException) {
            return $this->buildBridgeResponse(
                type: 'unauthenticated',
                status: 401,
                title: '' !== $e->getMessage() ? $e->getMessage() : 'Authentication required.',
                correlationId: $correlationId,
                instance: $instance,
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $type = self::HTTP_STATUS_TYPE_MAP[$status] ?? 'http-error';

            return $this->buildBridgeResponse(
                type: $type,
                status: $status,
                title: '' !== $e->getMessage() ? $e->getMessage() : 'An HTTP error occurred.',
                correlationId: $correlationId,
                instance: $instance,
            );
        }

        $message = $e->getMessage();
        $title = '' !== $message ? $message : 'An unexpected error occurred.';

        return new ProblemDetails(
            type: 'unhandled-exception',
            title: $title,
            status: 500,
            detail: null,
            instance: $instance,
            correlationId: $correlationId,
        );
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     *
     * @return list<array{field: string, message: string, code: string}>
     */
    private function buildViolations(iterable $violations): array
    {
        $out = [];

        foreach ($violations as $violation) {
            $out[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
                'code' => $violation->getCode() ?? '',
            ];
        }

        return $out;
    }

    private function buildBridgeResponse(
        string $type,
        int $status,
        string $title,
        string $correlationId,
        string $instance,
    ): ProblemDetails {
        return new ProblemDetails(
            type: $type,
            title: $title,
            status: $status,
            detail: null,
            instance: $instance,
            correlationId: $correlationId,
        );
    }

    /**
     * Walks `$throwable->getPrevious()` looking for an instance of `$class`. Mirrors
     * `SearchExceptionListener::findInChain` so the new ValidationFailedException branch
     * also unwraps Symfony's `RequestPayloadValueResolver` HttpException(422) wrapper
     * (used by `#[MapRequestPayload]` / `#[MapQueryString]` on non-search routes).
     *
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

    /**
     * Returns the FQCN of the first marker the exception declares, in `class_implements` order
     * intersected with the canonical marker list. Mirrors the precedence pinned by Story 1.1's
     * `testMarkerOrderingFollowsImplementsClause`.
     *
     * @return key-of<self::MARKER_STATUS_MAP>|null
     */
    private function firstMatchingMarker(Throwable $e): ?string
    {
        /** @var array<string, class-string> $implemented */
        $implemented = \class_implements($e);

        /** @var list<key-of<self::MARKER_STATUS_MAP>> $markers */
        $markers = \array_values(\array_intersect(
            $implemented,
            \array_keys(self::MARKER_STATUS_MAP),
        ));

        return $markers[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExtensions(DomainException $e): array
    {
        $context = $e->context();

        foreach (self::RESERVED_KEYS as $reserved) {
            unset($context[$reserved]);
        }

        $context = $this->redactKeys($context);

        $extensions = [];

        foreach ($context as $key => $value) {
            if ($this->isWhitelistedValue($value)) {
                $extensions[$key] = $value;

                continue;
            }

            // Story 3.3 seam wired here so the substitution path lights up when filled. This story
            // drops on type miss; Story 3.3 will substitute via `applyUnserializableSentinel()`.
            // @phpstan-ignore method.resultUnused
            $this->applyUnserializableSentinel($value);
        }

        return $extensions;
    }

    private function isWhitelistedValue(mixed $value): bool
    {
        return null === $value
            || \is_scalar($value)
            || \is_array($value)
            || $value instanceof JsonSerializable;
    }

    /**
     * Seam: filled by Story 3.2 (redaction denylist for `password`, `token`, etc.).
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function redactKeys(array $context): array
    {
        return $context;
    }

    /**
     * Seam: filled by Story 3.3 ('[unserializable]' substitution + structured log).
     */
    private function applyUnserializableSentinel(mixed $value): mixed
    {
        return $value;
    }
}
