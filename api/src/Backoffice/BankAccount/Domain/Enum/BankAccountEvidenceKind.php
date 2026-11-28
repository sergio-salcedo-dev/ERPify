<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Enum;

/**
 * What a document attached to a bank account proves. The retention terms belong to the kind, never to the caller: the
 * consumer fixes them here and the attach verifies on the server that the document was filed under exactly these, so
 * a client that declared other terms cannot attach the original it filed.
 *
 * The values mirror the Documents vocabulary (`RetentionReason`, `LegalBasis`) as strings, because this module may
 * not import that context's domain; `DocumentEvidenceReaderTest` holds every case to that vocabulary through
 * `from()`, so a renamed value reds a test instead of refusing every attach.
 */
enum BankAccountEvidenceKind: string
{
    public function retentionReason(): string
    {
        return match ($this) {
            self::OwnershipCertificate => 'contract-execution',
        };
    }

    public function legalBasis(): string
    {
        return match ($this) {
            self::OwnershipCertificate => 'legitimate-interests',
        };
    }

    /**
     * The bank's certificate that the holder owns the account. Kept for the execution of the contract it supports,
     * processed under legitimate interests pending the adviser's retention matrix, with no review date.
     */
    case OwnershipCertificate = 'ownership-certificate';
}
