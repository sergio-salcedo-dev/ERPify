<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Serializer;

use ArrayObject;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use UnexpectedValueException;

final readonly class ResourceNormalizer
{
    public function __construct(private NormalizerInterface $normalizer)
    {
    }

    /**
     * @param list<string> $groups
     *
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array, non-ArrayObject value
     *
     * @return array<string, mixed>
     */
    public function toArray(object $resource, array $groups, string $format = 'json'): array
    {
        /** @var array<string, mixed> */
        return $this->normalizeToArray($resource, $groups, $format);
    }

    /**
     * @param iterable<object> $resources
     * @param list<string>     $groups
     *
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array value
     *
     * @return list<mixed>
     */
    public function toList(iterable $resources, array $groups, string $format = 'json'): array
    {
        $items = \is_array($resources) ? $resources : \iterator_to_array($resources);

        /** @var list<mixed> */
        return \array_values(
            $this->normalizeToArray(
                \array_values($items),
                $groups,
                $format,
            ),
        );
    }

    /**
     * Normalizes a payload and guarantees an array result, unwrapping the {@see ArrayObject}
     * the inner normalizer emits for property-less objects (to preserve a `{}` JSON body).
     *
     * @param list<string> $groups
     *
     * @throws ExceptionInterface       when the inner normalizer fails
     * @throws UnexpectedValueException when the inner normalizer yields a non-array, non-ArrayObject value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeToArray(mixed $payload, array $groups, string $format): array
    {
        $normalized = $this->normalizer->normalize($payload, $format, ['groups' => $groups]);

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
