<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\AfterScenario;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;

/**
 * Asserts one privacy invariant after **every** scenario of the suite, rather than offering a step a feature
 * has to remember to call: no row of `audit_log.metadata` holds the identifier of a person the database still
 * knows.
 *
 * A hook, not a step, because the property it protects has no owner. `metadata` is written by ten paths — nine
 * `AuditLogger->log()` call sites plus the change-data-capture listener — and the last of those carries
 * whatever the entity model happens to expose, so it is not a reviewed call site at all. A step would only
 * ever be made where somebody already suspected the problem; a hook makes every scenario the suite already
 * runs into a witness, including the ones written for something else entirely.
 *
 * The JOIN is what makes it complementary to the scenario-scoped assertion in
 * `features/backoffice/users/erase.feature`, rather than a louder copy of it. That one names ONE subject and
 * is the only thing that can catch them, because an erased subject is gone from `identity_user` and this query
 * cannot see them. This one names nobody and catches everybody else — the administrator who executed an
 * erasure, an invited user, anyone whose id a future write path lets into the JSON.
 *
 * `ILIKE`, not `LIKE`: `resource_id` is a `uuid` and Postgres normalises its case on both sides of a
 * comparison, but `metadata` is jsonb TEXT and does not, while the HTTP layer hands an identifier down in
 * whatever case the caller spelled it.
 *
 * What it does NOT buy, said plainly: it is as deletable as any other test, so it widens coverage rather than
 * conferring immunity, and it proves nothing about a write path no scenario exercises. It also runs after the
 * scenario's own steps, so a query-budget assertion inside a scenario is measured before this ever executes.
 * And it is **vacuous whenever `identity_user` is empty** — the JOIN then returns nothing whatever `metadata`
 * holds, so a scenario that seeds no identity is not a witness at all, only a scenario that does not object.
 *
 * `audit_log` is not truncated between scenarios, so a single leak would otherwise be re-reported by every
 * scenario that follows it — 68 failures for one seeded row, none of them naming the scenario responsible.
 * Rows already reported are therefore remembered and excluded, which makes the FIRST failure the culprit's.
 * The memory is static because Behat builds a fresh context per scenario; it is scoped to one process and
 * dies with it, and its only effect is on which run reports a row, never on whether the row is a defect.
 *
 * **Read the exit code, not the tally.** Measured on Behat 4: when this hook fails, the run exits non-zero —
 * so CI gates on it correctly — but the summary still prints every scenario and step as passed, because a
 * failure raised in an `AfterScenario` hook marks no step and no scenario. A leak seeded into the generic
 * access-log path produced `69 scenarios (69 passed) · 601 steps (601 passed)` beside 68 of the failures
 * below, and an exit code of 2. Anyone reading the tally alone would call that green.
 */
final class PrivacyContext extends AbstractContext
{
    /**
     * Unbounded on purpose, unlike the assertion it feeds: a `LIMIT` here could fill with rows a previous
     * scenario already reported and hide the new one behind them. The green case returns nothing, so the
     * cost only grows once there is a defect to pay it for; what the failure PRINTS is bounded instead.
     */
    private const string LEAKED_PERSON_IDS = <<<'SQL'
        SELECT a.id, u.id AS person_id
        FROM audit_log a
        JOIN identity_user u ON a.metadata::text ILIKE '%' || u.id::text || '%'
        SQL;

    /** How many rows a failure names — one is the finding, the rest are only there to give it a shape. */
    private const int REPORTED = 5;

    /**
     * Findings already reported by an earlier scenario of this process, so the failure lands on the scenario
     * that introduced the leak rather than on all of its successors. Keyed by the whole `{audit row, person}`
     * pair rather than by the row alone: one row can leak two people's identifiers, and each is its own
     * finding.
     *
     * @var array<string, true>
     */
    private static array $alreadyReported = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws Exception
     */
    #[AfterScenario]
    public function noLivePersonIdSurvivesInAuditMetadata(): void
    {
        $rows = $this->entityManager->getConnection()->executeQuery(self::LEAKED_PERSON_IDS)->fetchAllAssociative();
        $leaked = [];

        foreach ($rows as $row) {
            $finding = \json_encode($row, JSON_THROW_ON_ERROR);

            if (isset(self::$alreadyReported[$finding])) {
                continue;
            }

            self::$alreadyReported[$finding] = true;
            $leaked[] = $row;
        }

        $leaked = \array_slice($leaked, 0, self::REPORTED);

        self::assertSame([], $leaked, \sprintf(
            'A person this database still knows has their identifier inside `audit_log.metadata`: %s. No '
            . 'mutation policy on that table enters the JSON — the prune deletes whole rows, and both '
            . 'anonymisers rewrite columns — so the id would outlive that person\'s erasure however carefully '
            . 'the erasure were written. Put the identifier on the resource axis, where the anonymiser reaches '
            . 'it, or keep it out of the entry altogether.',
            \json_encode($leaked, JSON_THROW_ON_ERROR),
        ));
    }
}
