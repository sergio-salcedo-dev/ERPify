<?php

declare(strict_types=1);

namespace Erpify\Shared\Serialization\Infrastructure;

use ArrayObject;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use UnexpectedValueException;

/**
 * Normalizes a resource into a serializable array with NO serializer groups and NO format context:
 * the per-view Resource DTO is already the projection, so whatever public shape it exposes is emitted
 * verbatim. Two consequences are load-bearing — only flat, scalar-only `Application/Resource/` DTOs may
 * flow through here: a non-scalar (e.g. a raw `DateTimeImmutable`) would emit a nested/non-ATOM shape
 * with no group or context to anchor it, and ungrouped normalization serializes every public property
 * and getter. That contract is enforced structurally by
 * {@see \Erpify\Tests\Unit\Shared\Serialization\ResourceDtoContractTest}; timestamps are pre-formatted
 * to ATOM strings by the mappers, never handed here as objects.
 */
final readonly class ResourceNormalizer
{
    public function __construct(private NormalizerInterface $normalizer)
    {
    }

    /**
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array, non-ArrayObject value
     *
     * @return array<string, mixed>
     */
    public function toArray(object $resource, string $format = 'json'): array
    {
        /** @var array<string, mixed> */
        return $this->normalizeToArray($resource, $format);
    }

    /**
     * @param iterable<object> $resources
     *
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array value
     *
     * @return list<mixed>
     */
    public function toList(iterable $resources, string $format = 'json'): array
    {
        $items = \is_array($resources) ? $resources : \iterator_to_array($resources);

        /** @var list<mixed> */
        return \array_values(
            $this->normalizeToArray(
                \array_values($items),
                $format,
            ),
        );
    }

    /**
     * Normalizes a payload and guarantees an array result, unwrapping the {@see ArrayObject}
     * the inner normalizer emits for property-less objects (to preserve a `{}` JSON body).
     *
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array, non-ArrayObject value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeToArray(mixed $payload, string $format): array
    {
        $normalized = $this->normalizer->normalize($payload, $format);

        if ($normalized instanceof ArrayObject) {
            /** @var array<array-key, mixed> */
            return $normalized->getArrayCopy();
        }

        if (!\is_array($normalized)) {
            throw new UnexpectedValueException(
                \sprintf('Expected normalize() to return array, got %s.', \get_debug_type($normalized)),
            );
        }

        return $normalized;
    }
}
