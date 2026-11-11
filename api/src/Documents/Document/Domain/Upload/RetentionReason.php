<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Upload;

/**
 * Why a document is kept, recorded at intake and enforced by nothing yet: period, anchor and jurisdiction wait
 * for counsel's retention matrix. A closed vocabulary so the matrix can be keyed on it later without parsing
 * free text; a value is added by a decision, never by a caller.
 */
enum RetentionReason: string
{
    case ContractExecution = 'contract-execution';
    case TaxAndAccounting = 'tax-and-accounting';
    case TechnicalFile = 'technical-file';
    case LegalClaims = 'legal-claims';
}
