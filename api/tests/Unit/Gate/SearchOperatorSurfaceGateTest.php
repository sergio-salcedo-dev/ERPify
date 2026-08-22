<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionParameter;
use SplFileInfo;

/**
 * `In` is the one operator in the filter vocabulary whose value is a LIST, so it is the one whose wire
 * spelling grows a sub-index (`filters[N][value][]`, `filters[N][value][0]`). A field admits it only by
 * declaring it; the default operator set of {@see FieldMapping} must not hand it out.
 *
 * The rule earned a gate because the default handed it out for a long time and nothing said so. Six field
 * mappings took the default, three of them over a person's email address, an account holder's name and an
 * IBAN — and the class that declined to redact that spelling at the log edge justified the declination as
 * "a form no field mapping currently admits", which was false while it was written.
 *
 * **The second assertion derives its universe from the tree** — it walks every `new FieldMapping(...)` under
 * `src` — so a repository added tomorrow joins it without anybody remembering to. The first reads one
 * default off the constructor by reflection and lists nothing either. A gate whose expectation and whose
 * subject come from the same hand-maintained list cannot detect a hole in that list, which is what the
 * enumeration this replaced could not do.
 *
 * **What a green does NOT prove.** That admitting `In` on some field is *safe* — that is a judgement, and
 * the control on it is review. Nor anything about the access log: the query string never reaches it
 * (`AccessLogQueryContainmentGateTest`), which is what makes this a capability gate rather than a
 * confidentiality one. It reads the tokens of each `new FieldMapping(...)`, so a mapping whose operators
 * arrive from a computed array, a constant, a variable or a factory that spreads its arguments is invisible
 * to the positional check below — it can see that a third argument was passed, never what is in it.
 *
 * @internal
 */
#[CoversNothing]
final class SearchOperatorSurfaceGateTest extends TestCase
{
    /**
     * Reads the parameter's own default rather than constructing a mapping, because a construction would
     * pass through the guards and a future guard could make the probe unrepresentative.
     */
    #[Test]
    public function theDefaultOperatorSetDoesNotHandOutTheListOperator(): void
    {
        $operators = $this->defaultOperators();

        $this->assertNotContains(FilterOperator::In, $operators, \sprintf(
            "The default operator set of FieldMapping grants `In` again (%s).\nEvery field mapping that "
                . 'does not name its own operators then admits a list value, including the ones over a '
                . "person's address, an account holder's name and an IBAN — none of which any call site "
                . 'or scenario in this tree filters with a list. Name `In` at the fields that want it, as '
                . 'the bank name and short code do.',
            \implode(', ', \array_map(static fn (FilterOperator $o): string => $o->value, $operators)),
        ));

        $this->assertNotEmpty(
            $operators,
            'The default operator set is empty, so this assertion holds vacuously. It must still describe '
            . 'the operators a field gets without asking.',
        );
    }

    /**
     * The other direction, and the one a reflection read cannot reach: a field must not acquire `In` by any
     * route other than naming it. Two ways it could, both refused here — passing the operator list
     * POSITIONALLY (the third constructor argument), and naming `FilterOperator::In` anywhere in a call
     * that does not use the named argument.
     *
     * A call is judged to name its own operators by searching for "operators:" with comments and string
     * literals stripped out first, never over the call's plain reconstructed source — which a comment or a
     * string argument could also contain, exempting a positional grant from both checks below.
     *
     * It also asserts the default is still REACHED. A default nothing reaches defends nothing: the case
     * above would keep passing while every field named its own operators, and keep passing on the day one
     * stopped.
     */
    #[Test]
    public function noFieldAcquiresTheListOperatorWithoutNamingIt(): void
    {
        $declarations = $this->fieldMappingDeclarations();

        $this->assertNotEmpty(
            $declarations,
            'No `new FieldMapping(` was found under src, so both directions of this gate are vacuous. The '
            . 'search surface has moved and this gate has to follow it.',
        );

        $inheritingTheDefault = [];

        foreach ($declarations as $declaration) {
            if ($declaration['namesOperators']) {
                continue;
            }

            $this->assertStringNotContainsString('FilterOperator::In', $declaration['source'], \sprintf(
                'A field mapping names `FilterOperator::In` without the `operators:` argument, so it grants '
                    . "the list operator where a reader looking for the decision will not find it:\n%s",
                $declaration['source'],
            ));

            $this->assertLessThanOrEqual(2, $declaration['arguments'], \sprintf(
                'A field mapping passes its operators positionally (%d arguments, no `operators:`). The '
                    . "grant is then invisible to every reader and to this gate's own check above:\n%s",
                $declaration['arguments'],
                $declaration['source'],
            ));

            $inheritingTheDefault[] = $declaration;
        }

        $this->assertNotEmpty($inheritingTheDefault, \sprintf(
            'Every one of the %d field mappings now names its own operators, so the default the case above '
                . 'guards reaches nothing and no longer defends anything reachable. Either the default '
                . 'should be removed outright or this gate should be.',
            \count($declarations),
        ));
    }

    /**
     * @return list<FilterOperator>
     */
    private function defaultOperators(): array
    {
        $parameter = new ReflectionParameter([FieldMapping::class, '__construct'], 'operators');

        $this->assertTrue(
            $parameter->isDefaultValueAvailable(),
            'FieldMapping::$operators no longer has a default, so this gate reads nothing. If the default '
            . 'was removed deliberately every field now declares its operators and this gate should go '
            . 'with it, not be relaxed.',
        );

        $default = $parameter->getDefaultValue();

        $this->assertIsArray($default, 'FieldMapping::$operators has a non-array default.');

        $operators = [];

        foreach ($default as $operator) {
            $this->assertInstanceOf(FilterOperator::class, $operator);
            $operators[] = $operator;
        }

        return $operators;
    }

    /**
     * Every `new FieldMapping(...)` under `src`, read from TOKENS rather than from text. A text scan cannot
     * tell a parenthesis inside a string literal from a real one, cannot skip a comment, and cannot see the
     * fully-qualified spelling of the class — three ways the same call would have been miscounted or missed.
     *
     * Returns the call's own source, its top-level argument count and whether it names a real `operators`
     * argument — the three facts that distinguish an operator list passed positionally from a field that
     * simply takes the default.
     *
     * @return list<array{source: string, arguments: int, namesOperators: bool}>
     */
    private function fieldMappingDeclarations(): array
    {
        $declarations = [];

        foreach ($this->sourceFiles() as $source) {
            foreach ($this->declarationsIn($source) as $declaration) {
                $declarations[] = $declaration;
            }
        }

        return $declarations;
    }

    /**
     * @return list<array{source: string, arguments: int, namesOperators: bool}>
     */
    private function declarationsIn(string $source): array
    {
        $tokens = \token_get_all($source);
        $declarations = [];

        for ($index = 0; isset($tokens[$index]); ++$index) {
            if (!$this->opensAFieldMapping($tokens, $index)) {
                continue;
            }

            $declarations[] = $this->declarationAt($tokens, $index);
        }

        return $declarations;
    }

    /**
     * `new` followed by the class name, whether written bare, imported, or fully qualified — the name
     * arrives as one `T_NAME_*` token in either spelling, so the tail is what identifies it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function opensAFieldMapping(array $tokens, int $index): bool
    {
        $token = $tokens[$index] ?? null;

        if (!\is_array($token) || T_NEW !== $token[0]) {
            return false;
        }

        for ($cursor = $index + 1; isset($tokens[$cursor]); ++$cursor) {
            $token = $tokens[$cursor];

            if (\is_array($token) && T_WHITESPACE === $token[0]) {
                continue;
            }

            return \is_array($token)
                && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && \str_ends_with($token[1], 'FieldMapping');
        }

        return false;
    }

    /**
     * Split in two on purpose: finding where the call ends is a walk over depth, and reading what it holds
     * is a walk over the slice. Doing both in one loop put the method over the complexity threshold, and
     * the version that did was also the one that silently truncated every declaration to `new `.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{source: string, arguments: int, namesOperators: bool}
     */
    private function declarationAt(array $tokens, int $index): array
    {
        $slice = \array_slice($tokens, $index, $this->lengthOfCallAt($tokens, $index));
        $source = '';
        $codeOnly = '';

        foreach ($slice as $slouse) {
            $text = \is_array($slouse) ? $slouse[1] : $slouse;
            $source .= $text;
            $codeOnly .= $this->isCommentOrStringLiteral($slouse) ? '' : $text;
        }

        return [
            'source' => $source,
            'arguments' => $this->argumentCountOf($slice),
            // A real named argument, read off CODE rather than off `$source`: a comment or a string
            // literal elsewhere in the call could also contain the words "operators:", which would then
            // exempt a positional grant from the check above without the call naming the argument at all.
            'namesOperators' => \str_contains($codeOnly, 'operators:'),
        ];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private function isCommentOrStringLiteral(array|string $token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function lengthOfCallAt(array $tokens, int $index): int
    {
        $depth = 0;
        $opened = false;

        for ($cursor = $index; isset($tokens[$cursor]); ++$cursor) {
            $depth += $this->depthDelta($tokens[$cursor]);
            $opened = $opened || $depth > 0;

            // Only once the argument list has actually OPENED. Stopping the moment the depth reads zero
            // ends the walk on the whitespace after `new`, which truncates the declaration to `new ` — and
            // both assertions over it then pass on a string that cannot contain what they look for.
            if ($opened && 0 === $depth) {
                return $cursor - $index + 1;
            }
        }

        return \count($tokens) - $index;
    }

    /**
     * Top-level arguments only: a comma nested in an array literal or a nested call belongs to that, not to
     * this call's signature. Counted by ARGUMENT-BEARING segment rather than by comma count, so a trailing
     * comma before the closing paren — which this repo's own fixer adds to a reformatted multi-line call —
     * does not inflate the count by one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $slice
     */
    private function argumentCountOf(array $slice): int
    {
        $tokens = $this->topLevelTokensOf($slice);

        if ([] === $tokens) {
            return 0;
        }

        if (',' === \array_last($tokens)) {
            \array_pop($tokens);
        }

        $commas = 0;

        foreach ($tokens as $token) {
            $commas += ',' === $token ? 1 : 0;
        }

        return $commas + 1;
    }

    /**
     * /**
     * Every token at the call's own top level (depth 1, the argument list itself) with whitespace dropped —
     * used to count arguments; the `operators:` detection below reads a differently filtered reconstruction
     * of the same slice instead, since a token-adjacency walk and a substring match cost the same here and
     * the substring reads far plainer.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $slice
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function topLevelTokensOf(array $slice): array
    {
        $depth = 0;
        $tokens = [];

        foreach ($slice as $slouse) {
            $depth += $this->depthDelta($slouse);

            if (1 === $depth && $this->carriesAnArgument($slouse)) {
                $tokens[] = $slouse;
            }
        }

        return $tokens;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private function depthDelta(array|string $token): int
    {
        return match ($token) {
            '(', '[' => 1,
            ')', ']' => -1,
            default => 0,
        };
    }

    /**
     * Anything that is not whitespace and not a bracket, i.e. the first sign that the call has an argument
     * at all — which is what separates `new FieldMapping()` from a one-argument call for the count.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private function carriesAnArgument(array|string $token): bool
    {
        return !\is_array($token) || T_WHITESPACE !== $token[0];
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $sources = [];
        $directory = new RecursiveDirectoryIterator(
            \dirname(__DIR__, 4) . '/src',
            FilesystemIterator::SKIP_DOTS,
        );

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false !== $contents) {
                $sources[] = $contents;
            }
        }

        return $sources;
    }
}
