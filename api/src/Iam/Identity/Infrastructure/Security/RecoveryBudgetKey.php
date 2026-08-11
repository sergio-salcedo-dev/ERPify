<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

/**
 * The bucket key every per-address budget over the recovery surface is keyed by.
 *
 * It exists as one function for the reason {@see \Erpify\Iam\Identity\Domain\Email} states about the value it
 * canonicalises for the unique constraint: split the function across two call sites and they acquire the
 * freedom to disagree about what "the same address" is. Here the two are the recovery budget and the budget
 * over its own observability, and a disagreement between them is silent — nothing breaks, the suppression
 * simply stops corresponding to the exhaustion it is suppressing, and the row count quietly doubles.
 *
 * It is NOT {@see \Erpify\Iam\Identity\Domain\Email}, and cannot be: that value object refuses a malformed
 * address, while these budgets are spent for EVERY requested address, well-formed or not, precisely so the
 * limiter itself cannot be probed for which addresses exist.
 */
final readonly class RecoveryBudgetKey
{
    public static function forEmail(string $email): string
    {
        return \mb_strtolower(\trim($email));
    }
}
