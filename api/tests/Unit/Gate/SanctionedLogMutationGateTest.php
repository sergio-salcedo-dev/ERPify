<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Closes the set of non-append mutations `src` may issue against `event_store` and `audit_log`.
 *
 * Both ADRs call these logs append-only "with a closed set of sanctioned mutations" (event-store-and-projections
 * D12, audit-activity-log D4), and for as long as that phrase had no gate behind it, "closed" meant only that a
 * reviewer might notice. The set is declared below, as a constant of this gate rather than a registry at the api
 * root: widening it is a diff to this file, which is exactly the moment a reviewer has to read. Four members
 * across the two tables — the GDPR rewrite of the event log, the two audit erasure axes and the audit retention
 * prune.
 *
 * The comparison is EQUALITY, not "found is a subset of declared". The missing direction is the one that keeps
 * the sweep honest: an extractor broken into seeing nothing would otherwise report the cleanest tree possible.
 * {@see SanctionedLogMutationRulesGateTest} falsifies the extractor against synthetic source, and
 * {@see SanctionedLogMutationBlindSpotGateTest} pins the shapes it does not reconstruct, listed below.
 *
 * {@see AuditPruneStatementGateTest} is not superseded: it pins the clauses of the prune (`ORDER BY id LIMIT
 * :batch FOR UPDATE`), which this gate does not read.
 *
 * **What a green proves:** every `.php` under `api/src` issues, against either log, exactly the mutations
 * declared here — no `UPDATE`, `DELETE`, `TRUNCATE`, `MERGE` or upsert (`INSERT … ON CONFLICT … DO UPDATE`)
 * elsewhere, and none of the four lost — as far as a statement is spelled in string literals, `.`
 * concatenations of them, heredocs, `self::`/`static::` string constants of the same file, or DBAL's
 * `->update('<log>')` / `->delete('<log>')` (positional, or a `table:` named argument in any position).
 *
 * **What it cannot see** (a green says nothing about these):
 *
 * - a table name reaching the statement through interpolation (an opaque gap inside the string), or through
 *   `sprintf`, a variable, a parameter or a constant of ANOTHER class (each ends the expression, so the verb
 *   before it and the table after it are read as two separate strings);
 * - DQL or ORM writes (no entity maps either log today), and raw SQL assembled at runtime;
 * - SQL built with `.=` or through parenthesised sub-expressions, and a constant named by its own class name
 *   (anything not spelled `self::`/`static::`);
 * - `static::NAME` overridden in a subclass: it resolves to the value of the file that spells it;
 * - a `;` inside a quoted SQL value, which cuts upsert and `TRUNCATE` detection short;
 * - WHICH row a declared member rewrites: a descriptor is verb and table only, so a sanctioned file that swaps
 *   its statement for another of the same verb on the same log — other columns, another predicate — stays
 *   green; the columns are each member's own tests;
 * - DDL (`DROP`/`ALTER TABLE`), which is not a row mutation and is out of the set — schema belongs to
 *   migrations;
 * - SQL outside `api/src`: `api/migrations` and `api/tests` are not swept on purpose (the fixture purger
 *   truncates the event backbone legitimately), nor are operator scripts or a psql session;
 * - database-side writers: a trigger, a rule, a function or a cascade;
 * - whether a declared mutation is correct, reached, or runs in the transaction its ADR requires — that is
 *   each member's own tests.
 *
 * **The fail-safe direction is red.** Any string literal in `src` that reads like a mutation — an exception
 * message, a statement stored whole in a constant AND used through `self::` (which counts twice), an `INSERT`
 * into a log followed in the same string by an upsert on another table — turns this gate red, and so does
 * `->update('<log>')` / `->delete('<log>')` on ANY receiver, a cache or a lock store included, since the
 * method name is all the engine reads. The failure message says so, so a false positive is not mistaken for a
 * change of policy.
 *
 * @internal
 */
#[CoversNothing]
final class SanctionedLogMutationGateTest extends TestCase
{
    /**
     * The closed set, file (relative to `api/src`) → mutations in order of appearance.
     */
    private const array SANCTIONED = [
        'Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php' => ['UPDATE audit_log'],
        'Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php' => ['DELETE audit_log'],
        'Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php' => ['UPDATE audit_log'],
        'Shared/Event/Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php' => ['UPDATE event_store'],
    ];

    #[Test]
    public function srcMutatesTheLogsThroughExactlyTheSanctionedSet(): void
    {
        $discrepancies = SanctionedLogMutations::discrepancies(self::SANCTIONED, $this->mutationsInSrc());

        $this->assertSame([], $discrepancies, \sprintf(
            "The closed set of mutations on event_store / audit_log no longer matches what src issues:\n- %s\n\n"
            . 'A new mutation on either log is a change of policy, not of code: it needs its own decision in '
            . 'docs/adr/event-store-and-projections.md (D12) or docs/adr/audit-activity-log.md (D4) and then a '
            . 'line in SANCTIONED. A sanctioned member the sweep no longer finds is either gone — remove it '
            . 'and say so in the ADR — or written in a shape the extractor cannot read, which leaves the set '
            . 'unguarded. A red can also be a false positive — the extractor reads string literals, so an '
            . 'exception message spelling a mutation, or a statement held whole in a constant and used through '
            . 'self::, counts, and so does ->update()/->delete() naming a log on any receiver; reword or '
            . 'restructure it rather than widening SANCTIONED.',
            \implode("\n- ", $discrepancies),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function mutationsInSrc(): array
    {
        $found = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            $this->assertIsString($source, \sprintf('%s could not be read.', $file->getPathname()));

            $mutations = SanctionedLogMutations::in($source);

            if ([] !== $mutations) {
                $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $mutations;
            }
        }

        return $found;
    }
}
