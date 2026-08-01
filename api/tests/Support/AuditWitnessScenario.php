<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reads an acceptance scenario as a corpus of text and answers one question about it: does a SINGLE scenario
 * seed a row of an audit `resource_type` and then assert that none of them survives?
 *
 * That question is what a `person` line of `api/.audit-resource-types` cannot answer about itself. The
 * erasure owner declared there can be shown to hold an anonymiser and call it — but a call that is written
 * is not a call that reaches a row, and the type's only literal in `src` is the constant that same owner
 * holds, so every check confined to `src` is satisfied by the declaration it is meant to verify.
 *
 * Two properties carry the weight:
 *
 *   - **Only STEPS count.** A `Feature:` line, a scenario title, description free text, an `Examples:` row
 *     and a comment are all text that never executes, and any of them naming the type beside the word INSERT
 *     would otherwise pass for a write. It is the rule the sibling wiring check applies to PHP source: an
 *     intention must not stand in for the act.
 *   - **One scenario has to do BOTH.** Matching the write and the assertion over the whole file lets the
 *     seed live in a scenario that asserts nothing and the assertion in a scenario that seeds nothing —
 *     the vacuous absence this gate exists to reject, assembled out of two honest halves.
 *
 * Read as TEXT, never executed: the scenario runs in the Behat suite, and none of that suite's costs (a
 * database, a reset, a seed connection) are paid here. `BehatSuiteCoverageGateTest` covers a different and
 * narrower thing — that every `.feature` under `api/features` sits beneath a declared suite path, and that
 * the default profile declares no filter. It does not establish that the scenario passes, or that Behat ran
 * at all, and it is reached by `make php.unit` rather than by the quality sweep this gate belongs to.
 *
 * @internal test support
 */
final readonly class AuditWitnessScenario
{
    /**
     * Gherkin's step keywords, `*` included — the bullet form the language also accepts.
     */
    private const string STEP = '/^(?:Given|When|Then|And|But|\*)\s/';

    /**
     * Openers of a scenario. `Background:` deliberately opens none: its steps belong to every scenario, and
     * attributing them to each would let a seed there pair with an assertion in whichever scenario follows.
     * A witness that seeds in `Background:` is refused loudly rather than accepted on a weaker rule.
     */
    private const string SCENARIO = '/^(?:Scenario|Example)\b/';

    public function __construct(private string $apiRoot)
    {
    }

    /**
     * Why the scenario at `$witnessPath` fails to witness the erasure of `$type`, or `null` when it does.
     *
     * Being a DIFFERENT artefact from the erasure owner is the whole mechanism — a check a declaration can
     * satisfy on its own carries no information — and that disjointness is structural rather than compared:
     * an owner is a `.php` under `src/` and a witness a `.feature` under `features/`, so no path can be
     * accepted as both. Relaxing either prefix takes the guarantee with it, which is why a `src/` path being
     * refused here is pinned as its own case.
     */
    public function defectIn(string $type, string $witnessPath): ?string
    {
        $unreadable = DeclaredPath::defectIn($this->apiRoot, $witnessPath, 'features/', 'feature');

        if (null !== $unreadable) {
            return \sprintf('the witness declared for "%s" is unusable: %s', $type, $unreadable);
        }

        $literal = \sprintf("'%s'", $type);
        $seeding = \array_filter(
            $this->scenariosIn($witnessPath),
            fn (array $steps): bool => $this->seeds($steps, $literal),
        );

        if ([] === $seeding) {
            return \sprintf(
                'no scenario of %s writes a row of "%s", so nothing it asserts is about that type',
                $witnessPath,
                $type,
            );
        }

        if (!\array_any($seeding, fn (array $steps): bool => $this->assertsNoneSurvives($steps, $literal))) {
            return \sprintf(
                '%s writes a row of "%s" but no scenario that seeds it asserts that no row of it survives, '
                . 'so it witnesses the write and not the erasure',
                $witnessPath,
                $type,
            );
        }

        return null;
    }

    /**
     * A step that puts a row of the type there. `INSERT … SELECT` is excluded because it inserts whatever the
     * query returns — nothing at all over an empty table — and a seed that seeds nothing is how a test comes
     * to assert an absence it never created. This repository has shipped that exact defect once.
     *
     * @param list<string> $steps
     */
    private function seeds(array $steps, string $literal): bool
    {
        return \array_any($steps, static fn (string $step): bool => \str_contains($step, $literal)
            && 1 === \preg_match('/\bINSERT\b/i', $step)
            && 1 !== \preg_match('/\bSELECT\b/i', $step));
    }

    /**
     * A read of the type answered by the very next step of the SAME scenario with a zero count. Adjacency is
     * what makes it mean something: a query and a count with other steps between them could be about
     * different result sets, and the scenario would then assert that some unrelated one was empty.
     *
     * @param list<string> $steps
     */
    private function assertsNoneSurvives(array $steps, string $literal): bool
    {
        foreach ($steps as $index => $step) {
            if (!$this->reads($step, $literal)) {
                continue;
            }

            if (1 === \preg_match('/\b0\s+(?:records|rows)\b/i', $steps[$index + 1] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function reads(string $step, string $literal): bool
    {
        return \str_contains($step, $literal) && 1 === \preg_match('/\bSELECT\b/i', $step);
    }

    /**
     * The steps of each scenario in the file, in order, with everything that is not a step dropped.
     *
     * @return list<list<string>>
     */
    private function scenariosIn(string $path): array
    {
        $scenarios = [];

        foreach (\preg_split('/\R/', (string) \file_get_contents($this->apiRoot . '/' . $path)) ?: [] as $line) {
            $trimmed = \trim($line);

            if (1 === \preg_match(self::SCENARIO, $trimmed)) {
                $scenarios[] = [];

                continue;
            }

            if ([] !== $scenarios && 1 === \preg_match(self::STEP, $trimmed)) {
                $scenarios[\array_key_last($scenarios)][] = $trimmed;
            }
        }

        return $scenarios;
    }
}
