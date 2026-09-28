<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Finds every non-append mutation a PHP source issues against `event_store` or `audit_log`, the two logs
 * whose ADRs promise a closed set of sanctioned mutations.
 *
 * A mutation is reported as a descriptor `"<VERB> <table>"`, VERB being one of `UPDATE`, `DELETE`,
 * `TRUNCATE`, `MERGE` or `UPSERT` (an `INSERT … ON CONFLICT … DO UPDATE`, which rewrites a row while reading
 * as an append). A plain `INSERT` and an `INSERT … ON CONFLICT … DO NOTHING` are appends and are not reported.
 *
 * Read over {@see PhpStringExpressions}, so a comment quoting `UPDATE audit_log` never counts, a statement
 * split across `.` concatenations or finished by a same-file `self::TABLE` still does, and heredocs are read
 * with their interpolations as opaque gaps. On top of the statements it also reads DBAL's table-level API
 * through {@see DbalTableApiCalls}, `->update('<log>', …)` and `->delete('<log>', …)`, which carry no SQL verb.
 *
 * What it cannot see is listed by the gate that relies on it,
 * {@see \Erpify\Tests\Unit\Gate\SanctionedLogMutationGateTest}.
 *
 * @internal test support
 */
final class SanctionedLogMutations
{
    private const string TABLES = '(event_store|audit_log)';

    /**
     * An optionally schema-qualified, optionally double-quoted table reference. The trailing look-ahead is
     * what keeps `audit_logger` from reading as `audit_log`.
     */
    private const string TABLE_REFERENCE
        = '(?:"?[A-Za-z_][A-Za-z0-9_]*"?\s*\.\s*)?"?' . self::TABLES . '"?(?![A-Za-z0-9_$])';

    /**
     * The mutations, in order of appearance, that the source issues against either log.
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
            $call = DbalTableApiCalls::at($expressions, $index, self::TABLE_REFERENCE);

            if (null !== $call) {
                $found[] = $call;
            }

            [$text, $next] = $expressions->at($index);

            if (null === $text) {
                ++$index;

                continue;
            }

            \array_push($found, ...self::mutationsInSql($text));
            $index = $next;
        }

        return $found;
    }

    /**
     * Every way `$found` departs from `$declared`, one sentence per departure, in both directions: a file
     * mutating a log without being declared, a declared file whose mutations changed, and a declared file
     * whose mutations are no longer found. The last one is what keeps the sweep from going vacuous — an
     * extractor that stops seeing anything reports exactly that, rather than a clean tree.
     *
     * @param array<string, list<string>> $declared
     * @param array<string, list<string>> $found
     *
     * @return list<string>
     */
    public static function discrepancies(array $declared, array $found): array
    {
        $discrepancies = [];

        foreach ($found as $file => $mutations) {
            if (!\array_key_exists($file, $declared)) {
                $discrepancies[] = \sprintf(
                    '%s issues %s but is not a sanctioned mutator.',
                    $file,
                    \implode(', ', $mutations),
                );

                continue;
            }

            if ($declared[$file] !== $mutations) {
                $discrepancies[] = \sprintf(
                    '%s is sanctioned for [%s] but issues [%s].',
                    $file,
                    \implode(', ', $declared[$file]),
                    \implode(', ', $mutations),
                );
            }
        }

        foreach ($declared as $file => $mutations) {
            if (!\array_key_exists($file, $found)) {
                $discrepancies[] = \sprintf(
                    '%s is sanctioned for [%s] but no longer issues any mutation the sweep can see.',
                    $file,
                    \implode(', ', $mutations),
                );
            }
        }

        return $discrepancies;
    }

    /**
     * @return list<string>
     */
    private static function mutationsInSql(string $sql): array
    {
        /** @var array<int, string> $found keyed by byte offset, so the result keeps statement order */
        $found = [];
        $patterns = [
            'UPDATE' => '/\bUPDATE\s+(?:ONLY\s+)?' . self::TABLE_REFERENCE . '/i',
            'DELETE' => '/\bDELETE\s+FROM\s+(?:ONLY\s+)?' . self::TABLE_REFERENCE . '/i',
            'MERGE' => '/\bMERGE\s+INTO\s+(?:ONLY\s+)?' . self::TABLE_REFERENCE . '/i',
        ];

        foreach ($patterns as $verb => $pattern) {
            \preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $found[$match[0][1]] = $verb . ' ' . \strtolower($match[1][0]);
            }
        }

        foreach (self::truncatedTables($sql) as $offset => $table) {
            $found[$offset] = 'TRUNCATE ' . $table;
        }

        foreach (self::upsertedTables($sql) as $offset => $table) {
            $found[$offset] = 'UPSERT ' . $table;
        }

        \ksort($found);

        return \array_values($found);
    }

    /**
     * `TRUNCATE` takes a list, and the log may sit anywhere in it.
     *
     * @return array<int, string>
     */
    private static function truncatedTables(string $sql): array
    {
        $found = [];
        \preg_match_all('/\bTRUNCATE\s+(?:TABLE\s+)?([^;]*)/i', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $offset = $match[0][1];

            foreach (\explode(',', $match[1][0]) as $position => $item) {
                if (1 === \preg_match('/^\s*(?:ONLY\s+)?' . self::TABLE_REFERENCE . '/i', $item, $table)) {
                    $found[$offset + $position] = \strtolower($table[1]);
                }
            }
        }

        return $found;
    }

    /**
     * An `INSERT` into a log is an append unless the same statement resolves its conflict by updating.
     *
     * @return array<int, string>
     */
    private static function upsertedTables(string $sql): array
    {
        $found = [];
        $pattern = '/\bINSERT\s+INTO\s+' . self::TABLE_REFERENCE . '/i';
        \preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $rest = \substr($sql, $offset + \strlen($match[0][0]));
            $end = \strpos($rest, ';');
            $statement = false === $end ? $rest : \substr($rest, 0, $end);

            if (1 === \preg_match('/\bON\s+CONFLICT\b.*?\bDO\s+UPDATE\b/is', $statement)) {
                $found[$offset] = \strtolower($match[1][0]);
            }
        }

        return $found;
    }
}
