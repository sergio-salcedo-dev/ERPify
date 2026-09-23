<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reads the second argument of every `->orderBy(…)` / `->addOrderBy(…)` call in a PHP source and says
 * whether it is a string.
 *
 * Lexing rather than line matching, for the reason {@see ClassImports} lexes; the navigation itself
 * lives in {@see ArgumentTokens}.
 *
 * **Depth zero, and that is a measurement rather than a preference.** The first version of this class
 * condemned any argument holding a `T_CONSTANT_ENCAPSED_STRING` at any depth. Run against the tree it
 * was written to protect, it reddened the correct code —
 * `addOrderBy($col, DoctrineSortDirection::from($column['direction']))`, where the string it found was
 * the array key `'direction'`, nested inside the conversion's own call. A rule that refuses the repair
 * is the wrong rule. Reading the argument's OWN top level separates the two without a list of approved
 * names and without resolving an alias: a literal or a `->value` there IS the argument, while anything
 * a call wraps is that call's business.
 *
 * What it therefore cannot see, and nothing else here can either: a string reached through a call
 * (`strtoupper($d->value)`), a variable that already holds one (`$order`), and a concatenation. Those
 * are unmeasured shapes rather than accepted ones — none occurs in this tree.
 *
 * Read only: the caller decides what the answer condemns.
 *
 * @internal test support
 */
final class OrderByArguments
{
    private const array METHODS = ['orderBy', 'addOrderBy'];

    /**
     * Every `->orderBy(…)` / `->addOrderBy(…)` call in the source. `carriesString` answers the question
     * above for the second argument and `text` reproduces it for a failure message; both are false and
     * empty when the call passes one argument, which `hasSecondArgument` distinguishes.
     *
     * The call list is returned whatever the arguments look like, so a gate can assert the sweep still
     * sees call sites and a silent parse failure cannot read as "no violations".
     *
     * @return list<array{method: string, line: int, hasSecondArgument: bool, carriesString: bool, text: string}>
     */
    public static function callsIn(string $source): array
    {
        $calls = [];
        $tokens = \token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (!self::isOrderByMethodName($token)) {
                continue;
            }

            if (!ArgumentTokens::endsWithObjectOperator(\array_slice($tokens, 0, $index))) {
                continue;
            }

            $second = self::secondArgumentAfter($tokens, $index);

            if (false === $second) {
                continue;
            }

            \assert(\is_array($token));

            $calls[] = [
                'method' => $token[1],
                'line' => $token[2],
                'hasSecondArgument' => null !== $second,
                'carriesString' => null !== $second && self::carriesStringAtTopLevel($second),
                'text' => null === $second ? '' : ArgumentTokens::textOf($second),
            ];
        }

        return $calls;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function isOrderByMethodName(array|string $token): bool
    {
        return \is_array($token) && T_STRING === $token[0] && \in_array($token[1], self::METHODS, true);
    }

    /**
     * The second argument of the call opening just after `$index`, `null` when the call passes only one,
     * and `false` when no argument list opens there at all — which means this was not a call site.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>|false|null
     */
    private static function secondArgumentAfter(array $tokens, int $index): array|false|null
    {
        $after = ArgumentTokens::significantTail($tokens, $index + 1);

        if ([] === $after || '(' !== $after[0]) {
            return false;
        }

        return ArgumentTokens::argumentAt(ArgumentTokens::insideBrackets($after), 2);
    }

    /**
     * Whether the argument's own top level is a string: a constant literal, or a `->value` read, which
     * is how a backed enum spells one.
     *
     * @param list<array{int, string, int}|string> $argument
     */
    private static function carriesStringAtTopLevel(array $argument): bool
    {
        foreach (ArgumentTokens::topLevelTokensOf($argument) as $index => $token) {
            if (!\is_array($token)) {
                continue;
            }

            if (T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                return true;
            }

            if (T_STRING !== $token[0] || 'value' !== $token[1]) {
                continue;
            }

            if (ArgumentTokens::endsWithObjectOperator(\array_slice($argument, 0, $index))) {
                return true;
            }
        }

        return false;
    }
}
