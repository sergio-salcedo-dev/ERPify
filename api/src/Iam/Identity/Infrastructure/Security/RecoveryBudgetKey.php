<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Email;

/**
 * The bucket key every per-address budget over the recovery surface is keyed by.
 *
 * It defers to {@see Email} rather than re-deriving the address, because that value object states — and owns —
 * what "the same mailbox" means: it is the canonicalisation function of the unique constraint, and every lookup
 * that resolves an identity goes through it. A budget keyed by any weaker rule silently stops corresponding to
 * the mailbox it is meant to protect. That is not hypothetical here: `mb_strtolower(trim(...))` alone leaves
 * U+00A0 in place while `Email` strips it, and strict RFC validation accepts an address padded with one — so
 * every padded spelling minted its own budget while resolving to a single identity, and the limiter could be
 * evaded by repeating the character.
 *
 * The fallback is what lets it stay a *budget* rather than an existence probe. `Email::tryFrom` answers null
 * for anything it cannot canonicalise, and those requests must still spend the bucket: a limiter that declined
 * to charge for malformed input would answer, by its own behaviour, which spellings denote a real mailbox.
 * Hence `tryFrom` and not `from` — the refusal of the value object is a signal here, never an error.
 */
final readonly class RecoveryBudgetKey
{
    public static function forEmail(string $email): string
    {
        return Email::tryFrom($email)?->toString() ?? \mb_strtolower(\trim($email));
    }
}
