<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every `#[AsCommand]` that puts an irreversible confirmation must refuse a run that cannot answer it, and
 * must do so in two places that are not interchangeable: before the question, and again immediately after it.
 *
 * **The obvious invariant is the wrong one, and it was measured wrong.** "Every command reaching `confirm()`
 * re-reads `isInteractive()` somewhere in the class" is satisfied by a class that re-reads it three methods
 * away, and it was green over the defect that produced this gate — a database call sitting outside the
 * ordering it was supposed to be inside. What makes the re-read mean anything is that nothing runs between
 * `confirm()` and it, because the flag it reads is set BY `confirm()`: the question helper catches its own
 * `MissingInputException`, flips the input to non-interactive and answers with the default. A statement
 * interposed there is a statement that acts on an answer nobody gave.
 *
 * So this asserts ADJACENCY, and separately that the pre-question refusal is present. It is deliberately a
 * text sweep rather than a behavioural one: what it has to catch is the fourth erasure command, which will be
 * written by copying whichever of the three its author finds first, and no behavioural test can be written
 * for a command that does not exist yet.
 *
 * **What a green does not prove.** It never judges whether the refusal is CORRECT — a command may re-read the
 * flag adjacently and then ignore the result, and this reads the shape rather than the semantics. It sees
 * only the spellings below, so a re-read reached through a differently named helper is invisible; it cannot
 * see a `confirm()` reached through a variable holding a `SymfonyStyle`; and it says nothing about the
 * failures a `try` cannot reach at all — an unknown option, a wrong arity, a mistyped name — which raise
 * before `execute()` and exit `1` however well guarded the confirmation is.
 *
 * @internal
 */
#[CoversNothing]
final class ConfirmationGuardAdjacencyGateTest extends TestCase
{
    /** How a command asks. Matched as a token, so a prompt reached through a variable is outside the sweep. */
    private const string CONFIRMATION = 'confirm';

    /** How a command reads the flag `confirm()` may have just flipped. */
    private const string REREAD = 'isInteractive';

    /** How a command refuses BEFORE asking, which covers the shapes no re-read can see. */
    private const string PREFLIGHT = 'cannotAnswer';

    /**
     * Statements allowed between the question and the re-read. One: the assignment that captures the answer.
     * Counted as `;` over the token stream rather than as lines, because a line window is a proxy for the
     * thing that matters and this is the thing itself.
     */
    private const int MAX_STATEMENTS_BETWEEN = 1;

    public function testEveryConfirmingCommandRefusesBeforeAskingAndRereadsImmediatelyAfter(): void
    {
        $confirming = $this->confirmingCommands();

        // The population is asserted so the sweep cannot go vacuous: a rename that stops matching the
        // confirmation would otherwise leave every row green over nothing.
        $this->assertGreaterThanOrEqual(
            3,
            \count($confirming),
            'the sweep found fewer confirming commands than the three GDPR erasures that exist',
        );

        foreach ($confirming as $path => $tokens) {
            $this->assertTrue(
                $this->callsMethod($tokens, self::PREFLIGHT),
                $path . ' asks for a confirmation without first refusing a run that cannot give one',
            );

            $this->assertTrue(
                $this->rereadsAdjacently($tokens),
                $path . ' does not re-read ' . self::REREAD . '() immediately after the confirmation — the'
                . ' flag that read tests is the one the question itself may have just flipped, so a statement'
                . ' in between acts on an answer nobody gave',
            );
        }
    }

    /**
     * @return array<string, list<array{int, string}>> path => the file's code tokens, comments dropped
     */
    private function confirmingCommands(): array
    {
        $found = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = (string) \file_get_contents($file->getPathname());

            if (!\str_contains($source, '#[AsCommand')) {
                continue;
            }

            $tokens = $this->codeTokens($source);

            if (!$this->callsMethod($tokens, self::CONFIRMATION)) {
                continue;
            }

            $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $tokens;
        }

        return $found;
    }

    /**
     * The file's tokens with comments and docblocks dropped. Reading the source as text instead was measured
     * failing OPEN on this very gate: a `{@see UnattendedRunPolicy::cannotAnswer()}` cross-reference in the
     * comment explaining the re-read satisfied a text match for the guard, so deleting the guard itself left
     * the gate green.
     *
     * @return list<array{int, string}>
     */
    private function codeTokens(string $source): array
    {
        $tokens = [];

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1]];

                continue;
            }

            $tokens[] = [-1, $token];
        }

        return $tokens;
    }

    /**
     * @param list<array{int, string}> $tokens
     */
    private function callsMethod(array $tokens, string $name): bool
    {
        return \array_any(\array_keys($tokens), fn (int $index): bool => $this->isCallTo($tokens, $index, $name));
    }

    /**
     * A `T_STRING` immediately followed by `(` — the shape of a call, as opposed to the same name appearing
     * in a type position, a docblock (already dropped) or a string.
     *
     * @param list<array{int, string}> $tokens
     */
    private function isCallTo(array $tokens, int $index, string $name): bool
    {
        $token = $tokens[$index] ?? null;

        if (null === $token || T_STRING !== $token[0] || $name !== $token[1]) {
            return false;
        }

        $next = $tokens[$index + 1] ?? null;

        return null !== $next && '(' === $next[1];
    }

    /**
     * @param list<array{int, string}> $tokens
     */
    private function rereadsAdjacently(array $tokens): bool
    {
        foreach (\array_keys($tokens) as $index) {
            if (!$this->isCallTo($tokens, $index, self::CONFIRMATION)) {
                continue;
            }

            if (!$this->rereadFollows($tokens, $index)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the re-read comes within {@see MAX_STATEMENTS_BETWEEN} statements of the question at `$from`.
     *
     * @param list<array{int, string}> $tokens
     */
    private function rereadFollows(array $tokens, int $from): bool
    {
        $statements = 0;

        for ($ahead = $from + 1, $count = \count($tokens); $ahead < $count; ++$ahead) {
            $token = $tokens[$ahead] ?? null;

            if (null === $token) {
                return false;
            }

            if (T_STRING === $token[0] && self::REREAD === $token[1]) {
                return true;
            }

            if (';' === $token[1] && ++$statements > self::MAX_STATEMENTS_BETWEEN) {
                return false;
            }
        }

        return false;
    }
}
