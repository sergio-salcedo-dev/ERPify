<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Gone;

/**
 * The content of a document the caller holds an id for is permanently unreadable: its row is `Erased`, its key was
 * destroyed, or the erasure ledger's override is set. The answers are one on purpose — a client must stop retrying and
 * a response that told them apart would say which erasure path ran. Carries no id, no scope and no cause: the sealed
 * layer's own exception names the scope, and that label stays in the log.
 */
final class DocumentGone extends DomainException implements Gone
{
    public const string TYPE = 'gone';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The document is gone.');
    }
}
