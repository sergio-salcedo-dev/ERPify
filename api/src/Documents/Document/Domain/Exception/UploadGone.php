<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Gone;

/**
 * The upload intent cannot take this PUT and never will: it does not exist, it belongs to another actor, or it
 * has expired. The three answer alike on purpose — the intent id is not a capability, and a response that told
 * "not yours" from "not there" would let a caller probe for intents it does not hold.
 */
final class UploadGone extends DomainException implements Gone
{
    public const string TYPE = 'gone';

    public static function create(): self
    {
        return new self(type: self::TYPE, title: 'The upload is gone; declare a new one.');
    }
}
