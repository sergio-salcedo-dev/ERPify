<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Composes the normalize-then-respond two-step shared by resource endpoints:
 * it turns a domain entity (or a collection of them) into a serialized payload
 * via {@see ResourceNormalizer} and hands the result to the format-agnostic
 * {@see ResponderInterface}. Controllers — and {@see SearchResponder} for the
 * paginated collection case — depend on this single collaborator instead of
 * wiring the normalizer and the responder by hand.
 */
final readonly class ResourceResponder
{
    public function __construct(
        private ResourceNormalizer $normalizer,
        private ResponderInterface $responder,
    ) {
    }

    /**
     * @param list<string> $groups serializer groups projecting the resource
     * @param int          $status Symfony HTTP status code (e.g. Response::HTTP_CREATED)
     *
     * @throws ExceptionInterface when normalization fails
     */
    public function respond(object $resource, array $groups, int $status = Response::HTTP_OK): Response
    {
        return $this->responder->respond(
            new Result($this->normalizer->toArray($resource, $groups), $status),
        );
    }

    /**
     * @param iterable<object>     $resources collection to serialize as the `data` array
     * @param list<string>         $groups    serializer groups projecting each resource
     * @param array<string, mixed> $meta      envelope keys emitted alongside `data` (e.g. `pagination`)
     * @param int                  $status    Symfony HTTP status code
     *
     * @throws ExceptionInterface when normalization fails
     */
    public function respondCollection(
        iterable $resources,
        array $groups,
        array $meta = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->responder->respond(
            new Result($this->normalizer->toList($resources, $groups), $status, $meta),
        );
    }
}
