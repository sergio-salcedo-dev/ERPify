<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The rule behind {@see \Erpify\Tests\Unit\Gate\MigrationColumnDefaultGateTest}: a migration
 * must not add a `NOT NULL` column and then drop the default that made the add possible.
 *
 * The shape is idiomatic and looks harmless — `ADD COLUMN … NOT NULL DEFAULT FALSE` backfills the existing
 * rows, and `DROP DEFAULT` afterwards keeps the live column matching a schema listener that expresses no
 * default, so `make db.diff` stays clean. What it leaves behind is a `NOT NULL` column with no default,
 * which breaks every `INSERT` issued by code that does not name it. That is reachable without any rolling
 * deploy: redeploying the previous image tag is the documented rollback (`docs/deployment-guide.md`) and
 * does **not** undo the migration with it, because `down()` never runs. One replica is enough.
 *
 * The rule is about the END STATE a migration leaves, not about the syntax that reaches it, because the
 * `ADD … NOT NULL DEFAULT x` + `DROP DEFAULT` pair is only the most idiomatic of four ways to arrive there.
 * A bare `ADD … NOT NULL` gets there in one statement, and adding a column nullable then tightening it with
 * `SET NOT NULL` gets there in two. All four break the same `INSERT`.
 *
 * **The boundary is what the file cannot say.** Whether a column added by SOME OTHER migration still has a
 * default is a question about the live database; whether the writer that will run against it names the
 * column is a question about an image no longer in the tree. Neither is answerable by parsing — do not try
 * to widen this rule into them, widen the coverage of `make db.validate` instead.
 *
 * **A column is identified by its table, not by its name.** `db.diff` writes multi-table migrations as a
 * matter of course and this schema repeats `status`, `user_id` and `created_at` across tables, so a rule
 * keyed on the bare name lets one table's correct `ADD status … DEFAULT` answer for another table's bare
 * `ADD status … NOT NULL` — a green over the very defect it exists to catch.
 *
 * **Only `up()` is read.** The reversal of a `DROP COLUMN` is an `ADD … NOT NULL` with no default, which is
 * what a correct `down()` looks like and is not a defect: `down()` runs against the schema its own `up()`
 * built, not against a live database an older image writes to. Reading both would fail the first migration
 * that deletes a `NOT NULL` column, and the only ways out of that red would be inventing a `DEFAULT` for the
 * reversal or growing the exemption list the gate keeps closed.
 *
 * Declared blind spots — a green proves the listed shapes are absent, nothing more:
 *  - Only the FIRST quoted literal of each `addSql()` is read, so SQL assembled by concatenating chunks is
 *    truncated. The one migration in the tree that concatenates builds a `CREATE TABLE`, which this rule
 *    does not look at.
 *  - The table is read from `ALTER TABLE`; a column statement reaching a table any other way is filed under
 *    `?` and shares that bucket with every other such statement in the file.
 *  - A source declaring no method at all is taken to BE an `up()` body, which is what the rule fixtures are.
 *    A migration class that reached `addSql()` from a helper method would go unread.
 *  - SQL built from a variable, a heredoc, or a loop is not read at all.
 *  - A column definition is taken to end at the next comma, which is what separates two `ADD` clauses in
 *    one statement. A type carrying a comma (`NUMERIC(10, 2)`) would truncate its own definition and read
 *    as nullable; the tree has none.
 *  - It never judges whether dropping the default was RIGHT. `identity_user.status` drops its own for a
 *    valid and different reason (the aggregate always sets the value, and a latent default would mask a
 *    write that forgot to) — which is why the exemptions are a closed list and not a suppression comment.
 */
final class MigrationColumnDefaults
{
    /**
     * The migrations that already carry the shape, frozen. It is a closed list and MUST NOT GROW: a new
     * entry means the defect shipped again, and the point of the gate is that it cannot. Removing one is
     * the only legal edit, and {@see \Erpify\Tests\Unit\Gate\MigrationColumnDefaultGateTest}
     * fails on a stale entry so a fixed migration cannot leave its exemption behind as cover for the next.
     *
     * Two of them are closed at the database by a later migration that re-adds the default; the exemption
     * stays because the file itself is immutable once merged. The `identity_user` pair is NOT a defect
     * awaiting a fix — see the class docblock.
     */
    public const array EXEMPT = [
        'Version20260626215406.php',
        'Version20260709230444.php',
        'Version20260711171040.php',
        'Version20260723151422.php',
    ];

    /**
     * The first quoted literal handed to `addSql()`, in either PHP quote style with escapes honoured.
     * Reading statements rather than raw file text is what lets a column definition contain a quoted SQL
     * literal — `DEFAULT 'ACTIVE' NOT NULL` — without the scan stopping at it and calling the column
     * nullable.
     */
    private const string SQL_LITERAL = '/addSql\(\s*(?:\'((?:[^\'\\\]|\\\.)*)\'|"((?:[^"\\\]|\\\.)*)")/s';

    /**
     * `ADD [COLUMN] [IF NOT EXISTS] <name>`, in both spellings the tree uses — Doctrine generates the bare
     * form, hand-written migrations use `COLUMN IF NOT EXISTS`.
     *
     * The excluded words are every `ADD` that is followed by something which is not a column name. Missing
     * one is not a missed defect but an invented one: `ADD CHECK (code IS NOT NULL)` would otherwise read as
     * a column called `check`, declared `NOT NULL`, with no default — a red on a correct migration whose only
     * exits are inventing a `DEFAULT` or growing an exemption list this gate keeps closed.
     */
    private const string ADDED_COLUMN = '/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?'
        . '(?!CONSTRAINT\b|CHECK\b|PRIMARY\b|UNIQUE\b|FOREIGN\b|EXCLUDE\b)([A-Za-z_]\w*)\b([^,]*)/i';

    /** `ALTER [COLUMN] <name> DROP DEFAULT`. `DROP NOT NULL` is a different statement and must not match. */
    private const string DEFAULT_DROPPED
        = '/\bALTER\s+(?:COLUMN\s+)?([A-Za-z_]\w*)\s+DROP\s+DEFAULT\b/i';

    /** `ALTER [COLUMN] <name> SET NOT NULL`. `SET DEFAULT` is a different statement and must not match. */
    private const string SET_NOT_NULL
        = '/\bALTER\s+(?:COLUMN\s+)?([A-Za-z_]\w*)\s+SET\s+NOT\s+NULL\b/i';

    /** `ALTER TABLE [IF EXISTS] <table>` — what qualifies a column name into an identity. */
    private const string ALTERED_TABLE = '/\bALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?([A-Za-z_]\w*)/i';

    /** A method declaration, read only to find where `up()` stops. */
    private const string METHOD_DECLARATION = '/\bfunction\s+([A-Za-z_]\w*)\s*\(/';

    /** Statements that name no table are filed together under a name no table can have. */
    private const string UNKNOWN_TABLE = '?';

    /**
     * The columns this migration leaves `NOT NULL` with no default. Only columns THIS file adds count, so a
     * `DROP DEFAULT` on a column another migration added — a legitimate reversal, and what a `down()` does —
     * is not mistaken for one.
     *
     * A column qualifies when the migration makes it `NOT NULL` by either route (declared in the `ADD`, or
     * tightened later with `SET NOT NULL`) and leaves it without a default by either route (never declared
     * one, or declared one and dropped it). The four combinations reach the same end state, and that state —
     * not the syntax that produced it — is what breaks the next `INSERT` from an image that predates the
     * column.
     *
     * @return list<string>
     */
    public static function violationsIn(string $source): array
    {
        $madeNotNull = self::columnsMadeNotNullIn($source);
        $defaultDropped = self::columnsLosingTheirDefaultIn($source);
        $violations = [];

        foreach (self::columnsAddedIn($source) as $column => $definition) {
            $notNull = $definition['notNull'] || \in_array($column, $madeNotNull, true);
            $keepsADefault = $definition['hasDefault'] && !\in_array($column, $defaultDropped, true);

            if ($notNull && !$keepsADefault) {
                $violations[] = $column;
            }
        }

        \sort($violations);

        return $violations;
    }

    /**
     * Every column this migration adds, keyed `<table>.<column>`, with the two facts about its declaration
     * the rule needs.
     *
     * @return array<string, array{notNull: bool, hasDefault: bool}>
     */
    public static function columnsAddedIn(string $source): array
    {
        $columns = [];

        foreach (self::tableSegmentsIn($source) as $segment) {
            \preg_match_all(self::ADDED_COLUMN, $segment['sql'], $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $columns[$segment['table'] . '.' . \strtolower($match[1])] = [
                    'notNull' => 1 === \preg_match('/\bNOT\s+NULL\b/i', $match[2]),
                    'hasDefault' => 1 === \preg_match('/\bDEFAULT\b/i', $match[2]),
                ];
            }
        }

        return $columns;
    }

    /**
     * Columns this migration promotes to `NOT NULL` after the fact. Adding a column nullable and tightening
     * it in a second statement reaches the same end state as declaring it `NOT NULL` outright.
     *
     * @return list<string>
     */
    public static function columnsMadeNotNullIn(string $source): array
    {
        return self::qualifiedColumnsMatching(self::SET_NOT_NULL, $source);
    }

    /**
     * @return list<string>
     */
    public static function columnsLosingTheirDefaultIn(string $source): array
    {
        return self::qualifiedColumnsMatching(self::DEFAULT_DROPPED, $source);
    }

    /**
     * The `addSql()` statements of `up()`, in order.
     *
     * @return list<string>
     */
    public static function statementsIn(string $source): array
    {
        \preg_match_all(self::SQL_LITERAL, self::upBodyOf($source), $matches, PREG_SET_ORDER);

        $statements = [];

        foreach ($matches as $match) {
            $statements[] = '' === ($match[1] ?? '') ? ($match[2] ?? '') : $match[1];
        }

        return $statements;
    }

    /**
     * @return list<string> `<table>.<column>`, deduplicated
     */
    private static function qualifiedColumnsMatching(string $pattern, string $source): array
    {
        $columns = [];

        foreach (self::tableSegmentsIn($source) as $segment) {
            \preg_match_all($pattern, $segment['sql'], $matches);

            // The pattern arrives as a parameter, so the capturing group is a promise of the two constants
            // above rather than something the type checker can read off a literal.
            foreach ($matches[1] ?? [] as $column) {
                $columns[] = $segment['table'] . '.' . \strtolower($column);
            }
        }

        return \array_values(\array_unique($columns));
    }

    /**
     * Every `up()` statement cut at its `ALTER TABLE` boundaries, so each piece is read against the table it
     * actually names. One `addSql()` can carry several statements, and reading the first table for all of
     * them puts a compliant column and a defective one under the same key — where the last write wins and
     * the offender is reported clean.
     *
     * @return list<array{table: string, sql: string}>
     */
    private static function tableSegmentsIn(string $source): array
    {
        $segments = [];

        foreach (self::statementsIn($source) as $statement) {
            \preg_match_all(self::ALTERED_TABLE, $statement, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            if ([] === $matches) {
                $segments[] = ['table' => self::UNKNOWN_TABLE, 'sql' => $statement];

                continue;
            }

            foreach ($matches as $index => $match) {
                $start = $match[0][1];
                $end = $matches[$index + 1][0][1] ?? \strlen($statement);

                $segments[] = [
                    'table' => \strtolower($match[1][0]),
                    'sql' => \substr($statement, $start, $end - $start),
                ];
            }
        }

        return $segments;
    }

    /**
     * The body of `up()`, from its declaration to whichever method is declared next.
     *
     * A source that declares no method is returned whole: that is the shape the rule fixtures take, and
     * reading it as anything else would leave them matching nothing — a rules test green over an empty set,
     * which is the exact failure the fixtures exist to rule out.
     */
    private static function upBodyOf(string $source): string
    {
        \preg_match_all(self::METHOD_DECLARATION, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $start = null;

        foreach ($matches as $match) {
            if (null !== $start) {
                return \substr($source, $start, $match[0][1] - $start);
            }

            if ('up' === \strtolower($match[1][0])) {
                $start = $match[0][1];
            }
        }

        return null === $start ? $source : \substr($source, $start);
    }

    /**
     * Every migration in the tree, by file name, recursing the per-year directories.
     *
     * @return list<string> absolute paths, sorted
     */
    public static function migrationFilesIn(string $directory): array
    {
        // Both levels. `organize_migrations: BY_YEAR` puts new files in a per-year directory, but Doctrine's
        // finder is recursive and includes the root, so a migration dropped there — the framework's own
        // default location — runs like any other and a one-level sweep would never see it.
        $files = [
            ...(\glob($directory . '/Version*.php') ?: []),
            ...(\glob($directory . '/*/Version*.php') ?: []),
        ];

        \sort($files);

        return $files;
    }
}
