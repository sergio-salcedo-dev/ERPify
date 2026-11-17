<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;

/**
 * The ledger write ran out of time, so whether its entry landed is not known. This request erased nothing, but an
 * entry that did land is completed forward by the completion sweep, so the document may be erased shortly without a
 * second request. A type of its own so a client does not retry blindly or tell the operator nothing happened.
 */
final class ErasureOutcomeUnknown extends DomainException implements ServiceUnavailable
{
    public const string TYPE = 'erasure-outcome-unknown';

    public static function create(): self
    {
        return new self(
            type: self::TYPE,
            title: 'The erasure may still complete. Check the document before sending the request again.',
        );
    }
}
