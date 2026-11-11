<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimited;

/**
 * A cap on concurrent uploads is reached: too many `Streaming` intents (per actor or globally) or too many live
 * `Declared` intents for this actor. The rate-limit problem type without `Retry-After`, because a cap has no
 * window to wait out: a slot frees when an upload ends, and nothing here can say when that is. The intent the
 * request named stays `Declared` and can be retried.
 */
final class UploadCapacityExhausted extends DomainException implements RateLimited
{
    public const string TYPE = 'rate-limited';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'Too many uploads in progress; retry later.');
    }
}
