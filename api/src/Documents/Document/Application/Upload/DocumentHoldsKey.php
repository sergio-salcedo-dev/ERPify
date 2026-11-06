<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\Entity\Document;
use RuntimeException;

/**
 * Thrown out of the declare transaction when its re-read finds a document already holding the key, so the
 * intent it just inserted rolls back with it. Internal to {@see DeclareUpload}; never reaches a caller.
 */
final class DocumentHoldsKey extends RuntimeException
{
    public function __construct(public readonly Document $document)
    {
        parent::__construct('A document already holds the idempotency key.');
    }
}
