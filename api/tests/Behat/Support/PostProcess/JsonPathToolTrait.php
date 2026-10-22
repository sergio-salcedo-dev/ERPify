<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use JsonException;

trait JsonPathToolTrait
{
    /**
     * A feature names a node relative to the document (`data[*].id`), while a JSONPath query is
     * required to start at the root identifier `$`; the root is supplied here so the step vocabulary
     * reads as the response does. A selector that already starts with `$` is passed through.
     *
     * @throws JSONPathException
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    public function getValues(string $nodeSelector): array
    {
        $query = \str_starts_with($nodeSelector, '$') ? $nodeSelector : '$.' . $nodeSelector;
        $values = (new JSONPath($this->getJson()->getContent()))->find($query)->getData();

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

        /** @var array<string, mixed> */
        return \json_decode(
            \json_encode($values, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
