<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Finds every statement a PHP source issues that changes or removes a row of `document_custody`, the
 * append-only custody log of the Documents context, on every surface a statement can take in this codebase.
 *
 * A finding is a descriptor `"<VERB> document_custody"`, VERB being one of `UPDATE`, `DELETE`, `TRUNCATE`,
 * `MERGE`, `UPSERT` (an `INSERT … ON CONFLICT … DO UPDATE`, which rewrites a row while reading as an append)
 * or `REMOVE` (the ORM's `remove()` in a file that names the entity). A plain `INSERT` is the append and is not
 * reported.
 *
 * Four surfaces, because the log is reachable four ways and a gate that read one would leave the others open:
 *
 *   - SQL text, read over {@see PhpStringExpressions} so a comment quoting a statement never counts and a
 *     statement split across `.` concatenations or finished by a same-file `self::` constant still does;
 *   - DBAL's table-level `->update('document_custody', …)` / `->delete('document_custody', …)`, through
 *     {@see DbalTableApiCalls};
 *   - DQL and the ORM query builder naming the entity class: `createQuery('UPDATE ' . DocumentCustody::class …)`,
 *     `->update(DocumentCustody::class, …)`, `->delete(DocumentCustody::class, …)` and the `->delete()` …
 *     `->from(DocumentCustody::class, …)` form, plus a DQL string spelling the class name;
 *   - `->remove(…)` on any receiver in a file that names the entity.
 *
 * It is the sibling of {@see SanctionedLogMutations}, which covers `event_store` and `audit_log` and hard-codes
 * them; this one names its own table so neither can silently widen the other. What it cannot see is listed by
 * the gate that relies on it, {@see \Erpify\Tests\Unit\Gate\DocumentCustodyMutationGateTest}.
 *
 * @internal test support
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") one method per write surface it reads
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") one branch per write surface it reads, which is the shape of
 *                                                      what the log can be reached through
 */
final class DocumentCustodyMutations
{
    private const string TABLE = 'document_custody';

    /**
     * The table as SQL spells it, or the entity as DQL spells it — the short name or any qualified form of it.
     * The look-behind keeps `OtherDocumentCustody` from reading as the entity, and the look-ahead keeps
     * `document_custody_archive` from reading as the table.
     */
    private const string REFERENCE
        = '(?:"?[A-Za-z_][A-Za-z0-9_]*"?\s*\.\s*)?"?'
        . '(document_custody|(?<![A-Za-z0-9_])(?:[A-Za-z_][A-Za-z0-9_]*\\\+)*DocumentCustody)'
        . '"?(?![A-Za-z0-9_$])';

    /** The table the log's foreign key points at, which `TRUNCATE … CASCADE` empties along with the log. */
    private const string PARENT_TABLE
        = '/^\s*(?:ONLY\s+)?(?:"?[A-Za-z_][A-Za-z0-9_]*"?\s*\.\s*)?"?document"?\s*\*?\s*$/i';

    private const string ENTITY = 'DocumentCustody';

    /**
     * The mutations, in order of appearance, that the source issues against the log.
     *
     * @return list<string>
     */
    public static function in(string $source): array
    {
        $expressions = new PhpStringExpressions($source);
        $found = [];
        $count = $expressions->count();
        $index = 0;

        while ($index < $count) {
            $call = DbalTableApiCalls::at($expressions, $index, self::REFERENCE);

            if (null !== $call) {
                $found[] = self::canonical($call);
            }

            $entity = self::entityClassMutationAt($expressions, $index);

            if (null !== $entity) {
                $found[] = $entity;
            }

            [$text, $next] = $expressions->at($index);

            if (null === $text) {
                ++$index;

                continue;
            }

            \array_push($found, ...self::mutationsInSql($text));
            $index = $next;
        }

        if (self::namesTheEntity($expressions)) {
            \array_push($found, ...self::removalsIn($expressions));
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function mutationsInSql(string $sql): array
    {
        // A comment between the verb and the table is still that statement: `UPDATE /* x */ document_custody`.
        $sql = (string) \preg_replace(['~/\*.*?\*/~s', '/--[^\n]*/'], ' ', $sql);
        /** @var array<int, string> $found keyed by byte offset, so the result keeps statement order */
        $found = [];
        $patterns = [
            'UPDATE' => '/\bUPDATE\s+(?:ONLY\s+)?' . self::REFERENCE . '/i',
            'DELETE' => '/\bDELETE\s+(?:FROM\s+)?(?:ONLY\s+)?' . self::REFERENCE . '/i',
            'MERGE' => '/\bMERGE\s+INTO\s+(?:ONLY\s+)?' . self::REFERENCE . '/i',
        ];

        foreach ($patterns as $verb => $pattern) {
            \preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $found[$match[1]] = $verb . ' ' . self::TABLE;
            }
        }

        foreach (self::truncations($sql) as $offset => $descriptor) {
            $found[$offset] = $descriptor;
        }

        foreach (self::upserts($sql) as $offset => $descriptor) {
            $found[$offset] = $descriptor;
        }

        \ksort($found);

        return \array_values($found);
    }

    /**
     * `TRUNCATE` takes a list, and the log may sit anywhere in it — or not be named at all: `TRUNCATE document
     * CASCADE` empties every table whose foreign key points at `document`, and this log's does.
     *
     * @return array<int, string>
     */
    private static function truncations(string $sql): array
    {
        $found = [];
        \preg_match_all('/\bTRUNCATE\s+(?:TABLE\s+)?([^;]*)/i', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $statement = $match[1][0];
            $cascades = 1 === \preg_match('/\bCASCADE\b/i', $statement);

            foreach (\explode(',', $statement) as $position => $item) {
                $item = (string) \preg_replace('/\s+(?:RESTART|CONTINUE|CASCADE|RESTRICT)\b.*$/is', '', $item);
                $names = 1 === \preg_match('/^\s*(?:ONLY\s+)?' . self::REFERENCE . '\s*\*?\s*$/i', $item);
                $parent = 1 === \preg_match(self::PARENT_TABLE, $item);

                if ($names || ($cascades && $parent)) {
                    $found[$offset + $position] = 'TRUNCATE ' . self::TABLE;
                }
            }
        }

        return $found;
    }

    /**
     * An `INSERT` into the log is an append unless the same statement resolves its conflict by updating.
     *
     * @return array<int, string>
     */
    private static function upserts(string $sql): array
    {
        $found = [];
        $insert = '/\bINSERT\s+INTO\s+' . self::REFERENCE . '/i';
        \preg_match_all($insert, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $rest = \substr($sql, $offset + \strlen($match[0][0]));
            $end = \strpos($rest, ';');
            $statement = false === $end ? $rest : \substr($rest, 0, $end);

            if (1 === \preg_match('/\bON\s+CONFLICT\b.*?\bDO\s+UPDATE\b/is', $statement)) {
                $found[$offset] = 'UPSERT ' . self::TABLE;
            }
        }

        return $found;
    }

    /**
     * The mutation the entity class named at `$index` is the target of, or null when it is only mentioned.
     *
     * Walks back from `DocumentCustody::class` to the call it is an argument of, and reads that call two
     * ways: by its method name (`update`, `delete`, and `from` when the chain opened with a bare `delete()` or
     * `update()`), and by the DQL text that precedes the class in the same argument (`'UPDATE ' . …::class`).
     */
    private static function entityClassMutationAt(PhpStringExpressions $expressions, int $index): ?string
    {
        if (!self::isEntityClassConstant($expressions, $index)) {
            return null;
        }

        $open = self::enclosingCallOpen($expressions, $index);

        if (null === $open) {
            return null;
        }

        $verb = self::verbOfCallAt($expressions, $open) ?? self::verbOfDqlBefore($expressions, $open, $index);

        return null === $verb ? null : $verb . ' ' . self::TABLE;
    }

    /**
     * The verb the call opened at `$open` names: by its own method (`update`, `delete`), or, for any other method
     * the class constant can be an argument of (`from`, `getRepository`, `createQueryBuilder`), by the fluent
     * chain it belongs to — read BACK to the start of the statement (`->delete()->from(X::class)`) and FORWARD
     * past the call (`->from(X::class)->delete()`, `getRepository(X::class)->createQueryBuilder('c')->update()`).
     */
    private static function verbOfCallAt(PhpStringExpressions $expressions, int $open): ?string
    {
        $method = $expressions->token($open - 1);

        if (!\is_array($method) || T_STRING !== $method[0]) {
            return null;
        }

        $name = \strtolower($method[1]);

        if (\in_array($name, ['update', 'delete'], true)) {
            return \strtoupper($name);
        }

        return self::chainVerbBefore($expressions, $open) ?? self::chainVerbAfter($expressions, $open);
    }

    /**
     * `UPDATE` or `DELETE` when the fluent chain continues past the call opened at `$open` with `->update(` or
     * `->delete(` at its own level, up to the end of the statement.
     */
    private static function chainVerbAfter(PhpStringExpressions $expressions, int $open): ?string
    {
        $depth = 0;
        $count = $expressions->count();

        for ($cursor = $open; $cursor < $count; ++$cursor) {
            $token = $expressions->token($cursor);
            $depth -= self::nestingDelta($token);

            if ($depth < 0 || (0 === $depth && ';' === $token)) {
                return null;
            }

            if (0 === $depth && self::isVerbMethodCall($expressions, $cursor) && \is_array($token)) {
                return \strtoupper($token[1]);
            }
        }

        return null;
    }

    /**
     * The verb a DQL string ends in just before the class constant, in the same argument list.
     */
    private static function verbOfDqlBefore(PhpStringExpressions $expressions, int $open, int $index): ?string
    {
        $leading = '';

        for ($cursor = $open + 1; $cursor < $index; ++$cursor) {
            [$text, $next] = $expressions->at($cursor);

            if (null !== $text) {
                $leading .= $text;
                $cursor = $next - 1;
            }
        }

        return match (true) {
            1 === \preg_match('/\bUPDATE\s*$/i', $leading) => 'UPDATE',
            1 === \preg_match('/\bDELETE(?:\s+FROM)?\s*$/i', $leading) => 'DELETE',
            default => null,
        };
    }

    private static function isEntityClassConstant(PhpStringExpressions $expressions, int $index): bool
    {
        $name = $expressions->token($index);
        $separator = $expressions->token($index + 1);
        $class = $expressions->token($index + 2);

        return self::isEntityName($name)
            && \is_array($separator) && T_DOUBLE_COLON === $separator[0]
            && \is_array($class) && T_CLASS === $class[0];
    }

    /**
     * @param array{int, string, int}|string|null $token
     */
    private static function isEntityName(array|string|null $token): bool
    {
        if (!\is_array($token) || !\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $segments = \explode('\\', \ltrim($token[1], '\\'));

        return self::ENTITY === \end($segments);
    }

    /**
     * Index of the `(` opening the call whose argument list holds `$index`, or null at the top of a statement.
     */
    private static function enclosingCallOpen(PhpStringExpressions $expressions, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
            $token = $expressions->token($cursor);

            if (\in_array($token, [')', ']', '}'], true)) {
                ++$depth;

                continue;
            }

            if (\in_array($token, ['(', '[', '{'], true)) {
                if (0 === $depth) {
                    return '(' === $token ? $cursor : null;
                }

                --$depth;

                continue;
            }

            if (';' === $token && 0 === $depth) {
                return null;
            }
        }

        return null;
    }

    /**
     * `UPDATE` or `DELETE` when the fluent chain this `->from(` continues opened with `->update()` or
     * `->delete()`, read back to the start of the statement.
     */
    private static function chainVerbBefore(PhpStringExpressions $expressions, int $open): ?string
    {
        $depth = 0;

        for ($cursor = $open - 2; $cursor >= 1; --$cursor) {
            $token = $expressions->token($cursor);
            $depth += self::nestingDelta($token);

            if ($depth < 0 || (0 === $depth && ';' === $token)) {
                return null;
            }

            if (0 === $depth && self::isVerbMethodCall($expressions, $cursor) && \is_array($token)) {
                return \strtoupper($token[1]);
            }
        }

        return null;
    }

    /**
     * +1 for a bracket that closes (walking backwards, it opens a level to skip), -1 for one that opens.
     *
     * @param array{int, string, int}|string|null $token
     */
    private static function nestingDelta(array|string|null $token): int
    {
        return match (true) {
            \in_array($token, [')', ']', '}'], true) => 1,
            \in_array($token, ['(', '[', '{'], true) => -1,
            default => 0,
        };
    }

    /**
     * Whether the token at `$cursor` is the name of an `->update(` / `->delete(` method after an object
     * operator.
     */
    private static function isVerbMethodCall(PhpStringExpressions $expressions, int $cursor): bool
    {
        $token = $expressions->token($cursor);
        $operator = $expressions->token($cursor - 1);

        if (!\is_array($token) || T_STRING !== $token[0] || !\is_array($operator)) {
            return false;
        }

        return \in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && \in_array(\strtolower($token[1]), ['update', 'delete'], true);
    }

    private static function namesTheEntity(PhpStringExpressions $expressions): bool
    {
        $count = $expressions->count();

        for ($index = 0; $index < $count; ++$index) {
            if (self::isEntityName($expressions->token($index))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `->remove(` call: the ORM names no entity in it, so the receiver's type is not recoverable from
     * tokens, and a file that names the log's entity and calls `remove()` is where the removal could be.
     *
     * @return list<string>
     */
    private static function removalsIn(PhpStringExpressions $expressions): array
    {
        $found = [];
        $count = $expressions->count();

        for ($index = 0; $index < $count; ++$index) {
            $operator = $expressions->token($index);
            $method = $expressions->token($index + 1);

            if (
                \is_array($operator) && \in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && \is_array($method) && T_STRING === $method[0] && 'remove' === \strtolower($method[1])
                && '(' === $expressions->token($index + 2)
            ) {
                $found[] = 'REMOVE ' . self::TABLE;
            }
        }

        return $found;
    }

    private static function canonical(string $descriptor): string
    {
        return \strtok($descriptor, ' ') . ' ' . self::TABLE;
    }
}
