<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocument;

/**
 * A link with what Documents says about its document, the carrier from the evidence use cases to the resource mapper.
 * `$document` is NULL only for a row the foreign key forbids (no document row), which the mapper shows as unknown.
 * Never serialised.
 */
final readonly class AttachedEvidence
{
    public function __construct(
        public BankAccountEvidence $link,
        public ?EvidenceDocument $document,
    ) {
    }
}
