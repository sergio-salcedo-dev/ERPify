<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Serializer;

use JsonException;
use Psr\Http\Message\ResponseInterface;

final class JsonDecoder
{
    private function __construct()
    {
    }

    /**
     * @throws JsonException
     *
     * @return array<array-key, mixed>
     */
    public static function decodeArray(string $json): array
    {
        $decoded = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new JsonException(
                \sprintf('Expected JSON object or array, got %s', \get_debug_type($decoded)),
            );
        }

        return $decoded;
    }

    /**
     * @throws JsonException
     *
     * @return array<array-key, mixed>
     */
    public static function decodeResponse(ResponseInterface $response): array
    {
        return self::decodeArray($response->getBody()->getContents());
    }
}
