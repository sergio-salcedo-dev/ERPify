<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The original filename the PUT carried is not one this context files. One answer for every rule it breaks,
 * and never the value: the name can carry a person's name or a third party's, and a problem body, an
 * exception message and a log context are each one more place it would outlive the document it names.
 */
final class InvalidOriginalFilename extends DomainException implements InvariantViolation
{
    public const string TYPE = 'invalid-filename';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The original filename is not acceptable.');
    }
}
