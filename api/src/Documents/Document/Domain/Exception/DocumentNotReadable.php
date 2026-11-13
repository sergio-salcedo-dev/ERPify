<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The document is not in `Ready`, the only state whose content a consumer may reach. Carries no state and no id:
 * the answer for `Received`, `Scanning`, `Quarantined`, `ScanFailed` and `Erased` is the same, so a caller learns
 * nothing about the scan from the refusal.
 */
final class DocumentNotReadable extends DomainException implements Conflict
{
    public const string TYPE = 'document-not-readable';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The document is not available for reading.');
    }
}
