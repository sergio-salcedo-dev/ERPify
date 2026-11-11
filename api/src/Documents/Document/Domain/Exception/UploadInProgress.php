<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Another request is streaming this upload right now. `retryAfterSeconds` is the remaining lease, which the
 * HTTP edge renders as `Retry-After`; it stays out of the context map, which travels in the body.
 */
final class UploadInProgress extends DomainException implements Conflict
{
    public const string TYPE = 'upload-in-progress';

    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(type: self::TYPE, title: 'The upload is already being received.');
    }

    public static function retryAfter(int $seconds): self
    {
        return new self(\max(1, $seconds));
    }
}
