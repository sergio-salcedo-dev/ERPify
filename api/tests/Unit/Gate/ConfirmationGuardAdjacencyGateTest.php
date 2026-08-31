<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every command that puts an irreversible confirmation must refuse a run that cannot answer it, and must do
 * so in two places that are not interchangeable: before the question, and again immediately after it.
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
 * text sweep rather than a behavioural one: what it has to catch is the fourth erasure command, and no
 * behavioural test can be written for a command that does not exist yet.
 *
 * **A command can now hold that property by either of two routes, and the sweep counts the UNION.** Two of
 * the three erasures inherit the sequence from {@see ConfirmedErasureCommand}, where they cannot get the
 * order wrong; the audit-trail erasure keeps its own, because it reads a row count between the refusal and
 * the question and expressing that as a hook would hide a real ordering decision. Inheritance is the
 * stronger route, but only for whoever takes it — so the weaker one stays asserted rather than retired.
 *
 * **The population is counted as COMMANDS REACHING A CONFIRMATION, not as files holding a token.** Counting
 * the token was the shape that would go silent: the day the third command inherits too, no `#[AsCommand]`
 * file contains `confirm(` at all, and a floor over those files passes at zero having proved nothing.
 *
 * **What a green does not prove.** It never judges whether the refusal is CORRECT — a command may re-read the
 * flag adjacently and then ignore the result, and this reads the shape rather than the semantics. It reads
 * the shape through two boundaries — the statement and the `match` arm — and a third spelling of "something
 * ran here" would be invisible again, so a green bounds the interposition it has been taught to see. It sees
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

    /** The base that holds the sequence, for the commands that inherit it rather than spelling it out. */
    private const string SHARED_BASE = 'ConfirmedErasureCommand';

    /**
     * Statements allowed between the question and the re-read. One: the assignment that captures the answer.
     * Counted as `;` over the token stream rather than as lines, because a line window is a proxy for the
     * thing that matters and this is the thing itself.
     *
     * A `match` arm ends in `,` and carries no `;` at all, so a table of arms reads as ONE statement to that
     * counter and any number of them could sit above the re-read unseen — which is why an arm separator
     * reached after the capture statement has ended counts as a boundary of its own.
     */
    private const int MAX_STATEMENTS_BETWEEN = 1;

    /**
     * The adjacency half, over every file that asks — the shared base included, since that is where two of
     * the three questions are now put.
     */
    public function testEveryFilePuttingAConfirmationRereadsTheFlagImmediatelyAfter(): void
    {
        $asking = $this->filesCalling(self::CONFIRMATION);

        $this->assertNotEmpty($asking, 'no file in the tree puts a confirmation, so this sweep proves nothing');

        foreach ($asking as $path => $tokens) {
            $this->assertTrue(
                $this->rereadsAdjacently($tokens),
                $path . ' does not re-read ' . self::REREAD . '() immediately after the confirmation — the'
                . ' flag that read tests is the one the question itself may have just flipped, so a statement'
                . ' in between acts on an answer nobody gave',
            );
        }
    }

    /**
     * The pre-question half, over every command that can reach a confirmation by either route.
     */
    public function testEveryConfirmingCommandRefusesARunThatCannotAnswer(): void
    {
        $confirming = $this->confirmingCommands();

        // The population is asserted so the sweep cannot go vacuous, and it is counted over the union of
        // both routes: a floor over files holding the token would pass at zero the day every command
        // inherits the sequence instead of spelling it out.
        $this->assertGreaterThanOrEqual(
            3,
            \count($confirming),
            'the sweep found fewer confirming commands than the three GDPR erasures that exist',
        );

        foreach ($confirming as $path => $tokens) {
            $this->assertTrue(
                $this->extendsSharedBase($tokens) || $this->callsMethod($tokens, self::PREFLIGHT),
                $path . ' can reach a confirmation without first refusing a run that cannot give one: it'
                . ' neither extends ' . self::SHARED_BASE . ' nor calls ' . self::PREFLIGHT . '() itself',
            );
        }
    }

    /**
     * Every `#[AsCommand]` that can reach a confirmation — by putting one itself, or by inheriting the
     * sequence that puts it.
     *
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

            if (!$this->callsMethod($tokens, self::CONFIRMATION) && !$this->extendsSharedBase($tokens)) {
                continue;
            }

            $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $tokens;
        }

        return $found;
    }

    /**
     * Every file in the tree whose tokens call `$name()`, command or not.
     *
     * @return array<string, list<array{int, string}>> path => the file's code tokens, comments dropped
     */
    private function filesCalling(string $name): array
    {
        $found = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $tokens = $this->codeTokens((string) \file_get_contents($file->getPathname()));

            if (!$this->callsMethod($tokens, $name)) {
                continue;
            }

            $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $tokens;
        }

        return $found;
    }

    /**
     * Whether the class declares the shared base as its parent. Read as the `extends` keyword followed by
     * the name, so the base's own file — which merely declares that name — is not mistaken for a subclass,
     * and neither is a docblock or a string mentioning it.
     *
     * @param list<array{int, string}> $tokens
     */
    private function extendsSharedBase(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (T_EXTENDS !== $token[0]) {
                continue;
            }

            $next = $this->nextMeaningful($tokens, $index);

            if (null !== $next && self::SHARED_BASE === $next[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next token that is not whitespace. Comments are already gone; whitespace is not, because the
     * adjacency check above counts `;` over the raw stream and must not have its spacing collapsed.
     *
     * @param list<array{int, string}> $tokens
     *
     * @return array{int, string}|null
     */
    private function nextMeaningful(array $tokens, int $from): ?array
    {
        for ($ahead = $from + 1, $count = \count($tokens); $ahead < $count; ++$ahead) {
            $token = $tokens[$ahead] ?? null;

            if (null !== $token && T_WHITESPACE !== $token[0]) {
                return $token;
            }
        }

        return null;
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

            // The arm boundary, counted apart from `;` rather than folded into the same counter: a `=>`
            // reached BEFORE the capture statement ends is an array key inside the question's own
            // arguments, and refusing that would red a call site that interposes nothing at all.
            if ($statements >= 1 && T_DOUBLE_ARROW === $token[0]) {
                return false;
            }
        }

        return false;
    }
}
