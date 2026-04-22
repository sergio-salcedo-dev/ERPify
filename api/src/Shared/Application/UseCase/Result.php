<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\UseCase;

/**
 * It describes the result of a use case.
 *
 * Controllers build a Result and hand it to a Responder; the Responder
 * decides the wire format (JSON today, XML / protobuf / whatever tomorrow).
 */
final readonly class Result
{
    public const int STATUS_OK = 200;

    public const int STATUS_CREATED = 201;

    public const int STATUS_NO_CONTENT = 204;

    public function __construct(
        public mixed $data = null,
        public int $status = self::STATUS_OK,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self($data, self::STATUS_OK);
    }

    /** @param array<string, mixed> $data */
    public static function created(array $data): self
    {
        return new self($data, self::STATUS_CREATED);
    }

    public static function noContent(): self
    {
        return new self(null, self::STATUS_NO_CONTENT);
    }
}
