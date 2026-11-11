<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The body did not become a document: it ended short of or ran past its declared size, its digest differs from
 * the declared one, the upload crossed its deadline or lost its lease. The staged object is deleted and the
 * intent expired; the caller declares again. One answer for every cause, carrying none of them.
 */
final class UploadAborted extends DomainException implements InvariantViolation
{
    public const string TYPE = 'aborted-upload';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The upload was aborted; declare a new one.');
    }
}
