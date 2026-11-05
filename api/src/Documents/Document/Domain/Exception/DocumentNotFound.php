<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * The document a custody row was to be appended to does not exist. Carries no id: the caller already holds
 * it, and an identifier in a problem body is one more place a reference outlives its subject.
 */
final class DocumentNotFound extends DomainException implements NotFound
{
    public static function create(): self
    {
        return new self(type: 'document-not-found', title: 'The document does not exist.');
    }
}
