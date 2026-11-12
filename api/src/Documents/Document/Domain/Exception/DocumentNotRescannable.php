<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * An operator asked to re-scan a document that is not in `ScanFailed`: a `Ready` document is never rescanned, a
 * `Quarantined` one is a verdict, and the others are mid-flight or terminal.
 */
final class DocumentNotRescannable extends DomainException implements Conflict
{
    public const string TYPE = 'document-not-rescannable';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'Only a document whose scan failed can be scanned again.');
    }
}
