<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The canonical form of an IBAN as this system stores and compares it: upper-case, with every
 * separator stripped that the validator gating the field already tolerates on the way in.
 *
 * The three characters are not a stylistic choice — they are the ones {@see Assert\Iban} removes
 * before it validates, so anything narrower accepts a value at the edge and then persists a form the
 * column's own invariant forbids. The consequence is not cosmetic: `bank_account.iban` is `unique`
 * and `#[UniqueEntity]` compares the raw column, so two spellings of one real IBAN would coexist as
 * two accounts, and a search for either would find neither.
 *
 * It lives in the domain because it decides identity, and both sides of that decision have to agree:
 * the aggregate uses it to canonicalize what it persists and to compare what it holds, and the search
 * normalizer uses it to canonicalize the value it binds. A rule written twice is a rule that drifts —
 * this one already had.
 */
final readonly class Iban
{
    /**
     * The separators {@see Assert\Iban} strips before validating: space, no-break space and narrow
     * no-break space. A copy-paste out of a bank statement or a PDF commonly carries the latter two.
     */
    private const array SEPARATORS = [' ', "\u{00A0}", "\u{202F}"];

    public static function canonicalize(string $iban): string
    {
        return \strtoupper(\str_replace(self::SEPARATORS, '', $iban));
    }
}
