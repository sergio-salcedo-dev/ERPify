<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

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
     * POSITIONALLY (the third constructor argument, which never contains the string `operators:`), and
     * naming `FilterOperator::In` anywhere in a call that does not use the named argument.
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
            if (\str_contains($declaration['source'], 'operators:')) {
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
     * Returns the call's own source and its top-level argument count, which is what distinguishes an
     * operator list passed positionally from a field that simply takes the default.
     *
     * @return list<array{source: string, arguments: int}>
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
     * @return list<array{source: string, arguments: int}>
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
     * @return array{source: string, arguments: int}
     */
    private function declarationAt(array $tokens, int $index): array
    {
        $slice = \array_slice($tokens, $index, $this->lengthOfCallAt($tokens, $index));
        $source = '';

        foreach ($slice as $slouse) {
            $source .= \is_array($slouse) ? $slouse[1] : $slouse;
        }

        return ['source' => $source, 'arguments' => $this->argumentCountOf($slice)];
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
     * this call's signature.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $slice
     */
    private function argumentCountOf(array $slice): int
    {
        $depth = 0;
        $commas = 0;
        $seenAnything = false;

        foreach ($slice as $slouse) {
            $depth += $this->depthDelta($slouse);

            if (1 !== $depth) {
                continue;
            }

            $commas += ',' === $slouse ? 1 : 0;
            $seenAnything = $seenAnything || $this->carriesAnArgument($slouse);
        }

        return $seenAnything ? $commas + 1 : 0;
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
