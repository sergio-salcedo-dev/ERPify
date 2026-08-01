<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reads an acceptance scenario as a corpus of text and answers one question about it: does it seed a row of
 * an audit `resource_type` and then assert that none of them survives?
 *
 * That question is what a `person` line of `api/.audit-resource-types` cannot answer about itself. The
 * erasure owner declared there can be shown to hold an anonymiser and call it — but a call that is written
 * is not a call that reaches a row, and the type's only literal in `src` is the constant that same owner
 * holds, so every check confined to `src` is satisfied by the declaration it is meant to verify.
 *
 * Read as TEXT, never executed: the scenario runs in the Behat suite, and none of that suite's costs (a
 * database, a reset, a seed connection) are paid here. `BehatSuiteCoverageGateTest` is the precedent — it
 * reads `api/features` from a unit gate the same way — and it is also what makes reading enough, since it
 * is the gate that keeps every scenario in the tree collected and unfiltered.
 *
 * @internal test support
 */
final readonly class AuditWitnessScenario
{
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

        $steps = $this->stepsOf($witnessPath);
        $literal = \sprintf("'%s'", $type);

        if (!$this->writes($steps, $literal)) {
            return \sprintf(
                '%s never writes a row of "%s", so nothing it asserts is about that type',
                $witnessPath,
                $type,
            );
        }

        if (!$this->assertsNoneSurvives($steps, $literal)) {
            return \sprintf(
                '%s writes a row of "%s" but never asserts that no row of it survives, so it witnesses the '
                . 'write and not the erasure',
                $witnessPath,
                $type,
            );
        }

        return null;
    }

    /**
     * A read of the type answered by the very next step with a zero count. Adjacency is what makes it mean
     * something: a query and a count separated by other steps could be about different result sets, and the
     * scenario would then be asserting that some unrelated one was empty.
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

    /**
     * @param list<string> $steps
     */
    private function writes(array $steps, string $literal): bool
    {
        return \array_any(
            $steps,
            static fn (string $step): bool => \str_contains($step, $literal)
                && 1 === \preg_match('/\bINSERT\b/i', $step),
        );
    }

    private function reads(string $step, string $literal): bool
    {
        return \str_contains($step, $literal) && 1 === \preg_match('/\bSELECT\b/i', $step);
    }

    /**
     * The scenario's steps: every non-blank line that is not a Gherkin comment.
     *
     * Dropping comments is the same rule the sibling wiring check applies to PHP source, and for the same
     * reason: a commented-out query naming the type is an intention, and an intention must not be able to
     * stand in for the write or for the assertion. Without this, a `# INSERT … 'User'` line — dead text —
     * would satisfy the write check on its own.
     *
     * Structural keywords stay in, so a `Scenario:` line separates two scenarios' steps and the adjacency
     * above cannot pair a query in one with a count in the next.
     *
     * @return list<string>
     */
    private function stepsOf(string $path): array
    {
        $steps = [];

        foreach (\preg_split('/\R/', (string) \file_get_contents($this->apiRoot . '/' . $path)) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' !== $trimmed && !\str_starts_with($trimmed, '#')) {
                $steps[] = $trimmed;
            }
        }

        return $steps;
    }
}
