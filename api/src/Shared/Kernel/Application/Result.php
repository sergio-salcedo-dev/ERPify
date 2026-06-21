<?php

declare(strict_types=1);

namespace Erpify\Shared\Kernel\Application;

/**
 * It describes the result of a use case.
 *
 * Controllers build a Result and hand it to a Responder; the Responder
 * decides the wire format (JSON today, XML / protobuf / whatever tomorrow).
 */
final readonly class Result
{
    public const int STATUS_OK = 200;

    public const int STATUS_NO_CONTENT = 204;

    /**
     * @param array<string, mixed> $meta envelope keys emitted as top-level siblings of `data`
     *                                   (e.g. `pagination`); must not carry a `data` key (payload wins)
     */
    public function __construct(
        public mixed $data = null,
        public int $status = self::STATUS_OK,
        public array $meta = [],
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, mixed>     $meta envelope keys emitted as top-level siblings of `data`
     *                                       (e.g. `pagination`); must not carry a `data` key (payload wins)
     */
    public static function ok(array $data, array $meta = []): self
    {
        return new self($data, self::STATUS_OK, $meta);
    }

    public static function noContent(): self
    {
        return new self(null, self::STATUS_NO_CONTENT);
    }
}
