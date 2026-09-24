<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * DBAL's table-level `->update('<table>', …)` / `->delete('<table>', …)`, the statement-free spelling of an
 * `UPDATE` / `DELETE` — read positionally, as a `table:` named argument in any position, and with an alias
 * after the name (`'audit_log a'`, `'audit_log AS a'`). Only the method name is read, never the receiver.
 *
 * Split from {@see SanctionedLogMutations}, which reads SQL statements; this reads call shapes.
 *
 * @internal test support
 */
final class DbalTableApiCalls
{
    /**
     * The `"<VERB> <table>"` descriptor of the call starting at `$index`, or null when it is not one naming a
     * table `$tableReference` (a regex fragment capturing the table name) matches.
     */
    public static function at(PhpStringExpressions $expressions, int $index, string $tableReference): ?string
    {
        $operator = $expressions->token($index);
        $method = $expressions->token($index + 1);

        if (!\is_array($operator) || !\in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return null;
        }

        if (!\is_array($method) || T_STRING !== $method[0] || '(' !== $expressions->token($index + 2)) {
            return null;
        }

        $verb = \strtoupper($method[1]);
        $table = self::tableArgumentAt($expressions, $index + 3, $tableReference);

        if (!\in_array($verb, ['UPDATE', 'DELETE'], true) || null === $table) {
            return null;
        }

        return $verb . ' ' . $table;
    }

    /**
     * The table a table-level call's table argument names — the first positional one, or a `table:` named one
     * wherever it sits — with an alias allowed after the name, or null when it names anything else.
     */
    private static function tableArgumentAt(
        PhpStringExpressions $expressions,
        int $index,
        string $tableReference,
    ): ?string {
        [$argument] = $expressions->at(self::tableArgumentIndex($expressions, $index));
        $alias = '(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?';

        if (
            null === $argument
            || 1 !== \preg_match('/^\s*' . $tableReference . $alias . '\s*$/i', $argument, $table)
            || !isset($table[1])
        ) {
            return null;
        }

        return \strtolower($table[1]);
    }

    /**
     * Where the value of a `table:` label at the call's top level starts — named arguments may come in any
     * order — or `$index`, the first argument, when the call names none.
     */
    private static function tableArgumentIndex(PhpStringExpressions $expressions, int $index): int
    {
        $depth = 0;
        $argumentStart = true;

        for ($cursor = $index; null !== ($token = $expressions->token($cursor)); ++$cursor) {
            if (0 === $depth && $argumentStart && self::isTableLabel($expressions, $cursor)) {
                return $cursor + 2;
            }

            $argumentStart = 0 === $depth && ',' === $token;

            if (self::opensNesting($token)) {
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true) && 0 === $depth--) {
                break;
            }
        }

        return $index;
    }

    private static function isTableLabel(PhpStringExpressions $expressions, int $index): bool
    {
        $token = $expressions->token($index);

        return \is_array($token) && T_STRING === $token[0] && 'table' === \strtolower($token[1])
            && ':' === $expressions->token($index + 1);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function opensNesting(array|string $token): bool
    {
        return \in_array($token, ['(', '[', '{'], true)
            || (\is_array($token) && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }
}
