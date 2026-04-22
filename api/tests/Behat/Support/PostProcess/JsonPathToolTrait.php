<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use JsonException;

trait JsonPathToolTrait
{
    /**
     * @throws JSONPathException
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    public function getValues(string $nodeSelector): array
    {
        $values = (new JSONPath($this->getJson()->getContent()))->find($nodeSelector)->getData();

        self::assertNotEquals(
            [],
            $values ?? [],
            \sprintf(
                'There is no data matching your selection (%s)',
                $nodeSelector,
            ),
        );

        if (!\is_array($values)) {
            $values = [$values];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            \json_encode($values, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
