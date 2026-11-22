<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * The predecessor already has a live successor — a version in `Received`, `Scanning` or `Ready` somewhere below it —
 * so a second one would fork the chain. Raised by the new-version commit and by the reopen of a failed version, both
 * under the series lock. Never retried by the server: a concurrent erasure or quarantine may free the slot a moment
 * later, and the caller may declare again.
 */
final class VersionSlotTaken extends DomainException implements Conflict
{
    public const string TYPE = 'document-version-slot-taken';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The predecessor already has a live successor.');
    }
}
