<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * Why the system, with no operator, erases a document: the ledger holds scope ids only, so the person who authorised
 * the erasure is not recoverable by design. Stored in `document_custody.reason` beside a NULL actor, and never
 * accepted over HTTP ({@see ErasureReason} is the route's type).
 */
enum ErasureSystemReason: string
{
    /** A restore found the scope in the ledger and finished what the restored database had lost. */
    case LedgerReplay = 'ledger-replay';

    /** The completion sweep found an entry that landed and an erasure that did not. */
    case LedgerCompletion = 'ledger-completion';
}
