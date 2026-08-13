<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;

/**
 * One line of the retention deletion plan: rows of {@see $level} whose `occurred_on` is before
 * {@see $deleteBefore} are out of retention, **except** those whose `action` is in {@see $exemptActions}.
 * The plan is data — {@see AuditRetentionPolicy} produces it and the pruner executes it — so the per-level
 * decision never leaks into the messaging/orchestration layer as control flow, and the exemption is a
 * domain value the adapter binds rather than a compliance literal spelled inside its SQL.
 *
 * **The parameter has no default and rejects the empty list.** Both halves are the same argument: a
 * threshold that exempts nothing is a licence to delete the trail's own erasure evidence, and making it
 * the value you get by forgetting is the fail-open shape this class exists to argue against. The guard is
 * what makes the argument enforceable rather than prose — and the failure it forecloses is silent in both
 * directions, because DBAL renders an empty list parameter as the literal token `NULL`, so binding it
 * would yield `action NOT IN (NULL)`, unknown for every row, and the prune would stop deleting anything
 * at all while reporting success. Every producer has to say what it exempts.
 *
 * **The exemption rides every line, not only the one whose level holds the evidence today.** Scoping it to
 * `security` would reopen the hole the day an evidence row is written at another level, silently and with
 * nothing red. On a level no evidence action reaches the predicate excludes nothing — every row still
 * matches `action NOT IN (…)` — so it changes which rows are deleted at exactly one level and costs the
 * others a comparison.
 */
final readonly class AuditRetentionThreshold
{
    /**
     * @param non-empty-list<string> $exemptActions
     */
    public function __construct(
        public AuditLevel $level,
        public DateTimeImmutable $deleteBefore,
        public array $exemptActions,
    ) {
        // @phpstan-ignore identical.alwaysFalse (runtime guard for callers bypassing the PHPDoc type)
        if ([] === $exemptActions) {
            throw InvalidAuditRetentionPolicy::exemptActionsMustNotBeEmpty($level->value);
        }
    }
}
