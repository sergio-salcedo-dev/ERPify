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
 * reads `api/features` from a unit gate the same way.
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

        $lines = $this->linesOf($witnessPath);
        $literal = \sprintf("'%s'", $type);

        if (!$this->writes($lines, $literal)) {
            return \sprintf(
                '%s never writes a row of "%s", so nothing it asserts is about that type',
                $witnessPath,
                $type,
            );
        }

        if (!$this->assertsNoneSurvives($lines, $literal)) {
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
     * A read of the type immediately answered with a zero count. Immediacy is what makes it mean something:
     * a query and a count separated by other steps could be about different result sets, and the scenario
     * would then be asserting that some unrelated one was empty.
     *
     * @param list<string> $lines
     */
    private function assertsNoneSurvives(array $lines, string $literal): bool
    {
        foreach ($lines as $index => $line) {
            if (!$this->reads($line, $literal)) {
                continue;
            }

            if (1 === \preg_match('/\b0\s+(?:records|rows)\b/i', $this->stepAfter($lines, $index))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private function writes(array $lines, string $literal): bool
    {
        return \array_any(
            $lines,
            static fn (string $line): bool => \str_contains($line, $literal)
                && 1 === \preg_match('/\bINSERT\b/i', $line),
        );
    }

    private function reads(string $line, string $literal): bool
    {
        return \str_contains($line, $literal) && 1 === \preg_match('/\bSELECT\b/i', $line);
    }

    /**
     * The next line carrying a step, skipping blanks and Gherkin comments — a comment between the query and
     * its count is idiomatic in this suite and must not read as the absence of an assertion.
     *
     * @param list<string> $lines
     */
    private function stepAfter(array $lines, int $index): string
    {
        foreach (\array_slice($lines, $index + 1) as $line) {
            $trimmed = \trim($line);

            if ('' !== $trimmed && !\str_starts_with($trimmed, '#')) {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $path): array
    {
        return \preg_split('/\R/', (string) \file_get_contents($this->apiRoot . '/' . $path)) ?: [];
    }
}
