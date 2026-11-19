<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;

/**
 * The erasure ledger could not make its entry durable, so nothing was erased: the document, its key and its custody
 * are as they were and the same request can be sent again. A {@see ServiceUnavailable} (503) so it reaches the error
 * tracker, because an operator who cannot erase is an operational fault.
 */
final class ErasureLedgerUnavailable extends DomainException implements ServiceUnavailable
{
    public const string TYPE = 'erasure-ledger-unavailable';

    public static function create(): self
    {
        return new self(
            type: self::TYPE,
            title: 'The erasure could not be recorded, so nothing was erased. Try again.',
        );
    }
}
