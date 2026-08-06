<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The rule behind {@see \Erpify\Tests\Unit\Shared\Architecture\MigrationColumnDefaultGateTest}: a migration
 * must not add a `NOT NULL` column and then drop the default that made the add possible.
 *
 * The shape is idiomatic and looks harmless — `ADD COLUMN … NOT NULL DEFAULT FALSE` backfills the existing
 * rows, and `DROP DEFAULT` afterwards keeps the live column matching a schema listener that expresses no
 * default, so `make db.diff` stays clean. What it leaves behind is a `NOT NULL` column with no default,
 * which breaks every `INSERT` issued by code that does not name it. That is reachable without any rolling
 * deploy: redeploying the previous image tag is the documented rollback (`docs/deployment-guide.md`) and
 * does **not** undo the migration with it, because `down()` never runs. One replica is enough.
 *
 * **This is the only case decidable by a textual sweep**, and the boundary is deliberate. Whether a column
 * added by SOME OTHER migration still has a default is a question about the live database, not about a
 * file; whether the writer that will run against it names the column is a question about an image that no
 * longer exists in the tree. Both are outside what any amount of parsing here could answer — do not try to
 * widen this rule into them, widen the coverage of `make db.validate` instead.
 *
 * Declared blind spots — a green proves the listed shapes are absent, nothing more:
 *  - Only the FIRST quoted literal of each `addSql()` is read, so SQL assembled by concatenating chunks is
 *    truncated. The one migration in the tree that concatenates builds a `CREATE TABLE`, which this rule
 *    does not look at.
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
     * the only legal edit, and {@see \Erpify\Tests\Unit\Shared\Architecture\MigrationColumnDefaultGateTest}
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
     * form, hand-written migrations use `COLUMN IF NOT EXISTS`. `ADD CONSTRAINT` is excluded by name
     * because it is the one `ADD` that is followed by an identifier and is not a column.
     */
    private const string ADDED_COLUMN
        = '/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(?!CONSTRAINT\b)([A-Za-z_]\w*)\b([^,]*)/i';

    /** `ALTER [COLUMN] <name> DROP DEFAULT`. `DROP NOT NULL` is a different statement and must not match. */
    private const string DEFAULT_DROPPED
        = '/\bALTER\s+(?:COLUMN\s+)?([A-Za-z_]\w*)\s+DROP\s+DEFAULT\b/i';

    /**
     * The columns this migration leaves `NOT NULL` with no default: added by it, and stripped of their
     * default by it. Keyed by name, so a `DROP DEFAULT` on a column some other migration added — which is
     * a legitimate reversal, and what `down()` does — is not mistaken for one.
     *
     * @return list<string>
     */
    public static function violationsIn(string $source): array
    {
        $violations = \array_values(\array_intersect(
            self::notNullColumnsAddedIn($source),
            self::columnsLosingTheirDefaultIn($source),
        ));

        \sort($violations);

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function notNullColumnsAddedIn(string $source): array
    {
        $columns = [];

        foreach (self::statementsIn($source) as $statement) {
            \preg_match_all(self::ADDED_COLUMN, $statement, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (1 === \preg_match('/\bNOT\s+NULL\b/i', $match[2])) {
                    $columns[] = \strtolower($match[1]);
                }
            }
        }

        return \array_values(\array_unique($columns));
    }

    /**
     * @return list<string>
     */
    public static function columnsLosingTheirDefaultIn(string $source): array
    {
        $columns = [];

        foreach (self::statementsIn($source) as $statement) {
            \preg_match_all(self::DEFAULT_DROPPED, $statement, $matches);

            foreach ($matches[1] as $column) {
                $columns[] = \strtolower($column);
            }
        }

        return \array_values(\array_unique($columns));
    }

    /**
     * @return list<string>
     */
    public static function statementsIn(string $source): array
    {
        \preg_match_all(self::SQL_LITERAL, $source, $matches, PREG_SET_ORDER);

        $statements = [];

        foreach ($matches as $match) {
            $statements[] = '' === ($match[1] ?? '') ? ($match[2] ?? '') : $match[1];
        }

        return $statements;
    }

    /**
     * Every migration in the tree, by file name, recursing the per-year directories.
     *
     * @return list<string> absolute paths, sorted
     */
    public static function migrationFilesIn(string $directory): array
    {
        $files = \glob($directory . '/*/Version*.php') ?: [];

        \sort($files);

        return $files;
    }
}
