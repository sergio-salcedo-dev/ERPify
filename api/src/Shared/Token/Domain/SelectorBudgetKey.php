<?php

declare(strict_types=1);

namespace Erpify\Shared\Token\Domain;

/**
 * The bucket key a per-selector budget over a `<selector>.<secret>` link is keyed by.
 *
 * It lives beside {@see SingleUseToken} rather than in either module that spends it, because the rule is a
 * property of the link primitive itself: three surfaces present one — invitation acceptance, password reset
 * and recovery-secret redemption — and a rule that lived in one of them would either be copied or reached for
 * across a bounded-context boundary.
 *
 * **A selector's spelling is not its identity, and keying on the spelling deletes the budget.** The selector
 * is a UUID: `Uuid::isValid()` accepts either case, and the column it selects is a Postgres-native `uuid`,
 * which compares case-insensitively. Measured against the running database —
 * `SELECT '0190…4a5b'::uuid = '0190…4A5B'::uuid` answers `t` — so one row answers to roughly two thousand
 * spellings for a v7 id (11–12 of its 32 hex digits are letters), and a raw key mints a fresh bucket for
 * each. A limit of ten per window then becomes tens of thousands of attempts against a single selector, with
 * only the per-IP budget left underneath. Case-folding is what makes "per selector" true rather than merely
 * written down.
 *
 * This is the defect {@see \Erpify\Iam\Identity\Infrastructure\Security\RecoveryBudgetKey} already records
 * for the ADDRESS axis — "a budget keyed by any weaker rule silently stops corresponding to the mailbox it is
 * meant to protect" — reaching the selector axis, where it had gone unnoticed on all three surfaces.
 *
 * Lower-cased and NOT canonicalised through a UUID value object, deliberately: this must charge for malformed
 * input too. A key that declined to fold what it could not parse would answer, by its own behaviour, which
 * spellings are well-formed — and the whole point of these budgets is that exhaustion is indistinguishable
 * from a dead link.
 */
final readonly class SelectorBudgetKey
{
    public static function of(string $selector): string
    {
        return \mb_strtolower(\trim($selector));
    }
}
