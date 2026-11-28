<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Enum;

/**
 * What a piece of evidence attached to a bank account proves. Each kind fixes the retention terms its documents must
 * have been filed under: the consumer decides them per kind, never the screen and never the caller, so two
 * certificates of one kind cannot be kept for different reasons. A kind is added by a decision.
 *
 * The terms are the wire strings of the Documents vocabulary (`RetentionReason`, `LegalBasis`), spelled here because
 * this context may not import that one's `Domain`; the attach compares them through the seam Documents publishes.
 */
enum EvidenceKind: string
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
     * A bank's certificate that the account belongs to its holder. Kept to perform the contract the account serves,
     * under legitimate interests pending the adviser's retention matrix, with no review date.
     */
    case OwnershipCertificate = 'ownership-certificate';
}
