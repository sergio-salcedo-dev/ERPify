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
        $normalized = $this->normalizer->normalize($resource, $format, ['groups' => $groups]);

        if ($normalized instanceof ArrayObject) {
            /** @var array<string, mixed> $copy */
            $copy = $normalized->getArrayCopy();

            return $copy;
        }

        if (!\is_array($normalized)) {
            throw new UnexpectedValueException(
                \sprintf('Expected normalize() to return array, got %s.', \get_debug_type($normalized)),
            );
        }

        /** @var array<string, mixed> $normalized */
        return $normalized;
    }
}
