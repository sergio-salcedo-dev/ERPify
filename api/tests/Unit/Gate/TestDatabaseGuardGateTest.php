<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Database\RefuseRuntimeDatabaseGuard;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the guard that keeps the suite off the runtime database, in the two directions nothing else covers.
 *
 * `TestDatabaseIsolationTest` proves the CONNECTION is right; it cannot prove the guard ran, and would stay
 * green if the call were deleted. Nothing else drives the refusal branch at all, so the guard could stop
 * refusing and every gate in the repository would still pass — the failure mode its own docblock names.
 *
 * The call-site half reads the two entry points as data: the PHPUnit file bootstrap, which covers every
 * `bin/phpunit` invocation, and the CLI script `db.test.prepare` runs before its migrate. Both are needed:
 * the target is a make PREREQUISITE, so it executes before the bootstrap ever loads.
 *
 * **A green proves the call is WRITTEN, never that it runs.** Comments are stripped before the match, so a
 * commented-out call reds; a call after an early `return`, or under a condition that is never true, still
 * passes. Closing that needs the entry points executed rather than read, which is a different instrument —
 * both are unconditional and last in their file today, and review is the only control on that staying true.
 *
 * @internal
 */
#[CoversNothing]
final class TestDatabaseGuardGateTest extends TestCase
{
    private const string RUNTIME_DSN = 'postgresql://u:p@database:5432/erpify_db?serverVersion=18';

    private string $configPath = '';

    protected function tearDown(): void
    {
        if ('' !== $this->configPath && \is_file($this->configPath)) {
            \unlink($this->configPath);
        }

        parent::tearDown();
    }

    public function testItAcceptsASuffixCarryingTheTestMarker(): void
    {
        $this->expectNotToPerformAssertions();

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
            self::RUNTIME_DSN,
            $this->configDeclaring("'_test%env(default::TEST_TOKEN)%'"),
            null,
        );
    }

    public function testItAcceptsALaneTokenAppendedAfterTheMarker(): void
    {
        $this->expectNotToPerformAssertions();

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
            self::RUNTIME_DSN,
            $this->configDeclaring("'_test%env(default::TEST_TOKEN)%'"),
            '_behat',
        );
    }

    public function testItRefusesASuffixWithoutTheTestMarker(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to run the suite against "erpify_db_scratch"/');

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
            self::RUNTIME_DSN,
            $this->configDeclaring("'_scratch'"),
            null,
        );
    }

    public function testItRefusesWhenTheSuffixIsAbsentAltogether(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No `doctrine\.dbal\.dbname_suffix`/');

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(self::RUNTIME_DSN, $this->configWithoutSuffix(), null);
    }

    public function testItRefusesRatherThanSkippingWhenTheDsnIsUnusable(): void
    {
        $config = $this->configDeclaring("'_test'");

        $this->expectException(RuntimeException::class);
        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(null, $config, null);
    }

    public function testItRefusesWhenTheConfigFileIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No Doctrine test config/');

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
            self::RUNTIME_DSN,
            \sys_get_temp_dir() . '/erpify-absent-' . \uniqid() . '.yaml',
            null,
        );
    }

    /**
     * The repository's own config must satisfy its own guard — otherwise the cases above pin a rule the tree
     * does not follow.
     */
    public function testTheRepositoryConfigSatisfiesTheGuard(): void
    {
        $this->expectNotToPerformAssertions();

        RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
            self::RUNTIME_DSN,
            $this->apiRoot() . '/config/packages/test/doctrine.yaml',
            null,
        );
    }

    public function testBothEntryPointsStillCallTheGuard(): void
    {
        foreach (['tools/phpunit/bootstrap.php', 'tools/phpunit/assert-test-database.php'] as $entryPoint) {
            $this->assertStringContainsString(
                'RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(',
                $this->codeOf($this->read($this->apiRoot() . '/' . $entryPoint)),
                \sprintf('%s no longer calls the guard, so that entry point runs unprotected.', $entryPoint),
            );
        }
    }

    /**
     * `db.test.prepare` is a prerequisite of the PHPUnit targets, so make runs its migrate before the file
     * bootstrap loads. The assertion is on the ORDER, which is the whole reason the CLI entry point exists.
     */
    public function testDbTestPrepareRunsTheGuardBeforeItMigrates(): void
    {
        // Scoped to the target's own recipe: `doctrine:migrations:migrate` also appears in `db.migrate`, far
        // earlier in the file, and searching the whole text compares against that one instead.
        $recipe = $this->recipeOf($this->read($this->repoRoot() . '/make/db.mk'), 'db.test.prepare');

        $guardCall = \strpos($recipe, 'tools/phpunit/assert-test-database.php');
        $migrate = \strpos($recipe, 'doctrine:migrations:migrate');

        $this->assertIsInt($guardCall, 'db.test.prepare no longer runs the database guard.');
        $this->assertIsInt($migrate, 'db.test.prepare no longer migrates; this assertion is stale.');
        $this->assertLessThan(
            $migrate,
            $guardCall,
            'db.test.prepare migrates before it checks the database, so a broken suffix migrates the runtime one.',
        );
    }

    private function configDeclaring(string $suffix): string
    {
        return $this->writeConfig(\sprintf("doctrine:\n    dbal:\n        dbname_suffix: %s\n", $suffix));
    }

    private function configWithoutSuffix(): string
    {
        return $this->writeConfig("doctrine:\n    dbal:\n        profiling: true\n");
    }

    private function writeConfig(string $contents): string
    {
        $this->configPath = \sys_get_temp_dir() . '/erpify-guard-' . \uniqid() . '.yaml';
        \file_put_contents($this->configPath, $contents);

        return $this->configPath;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * The PHP source with its comments removed, because the needle below is a call and a text match cannot
     * tell one from prose about one: measured, commenting the call out leaves `str_contains()` true, so the
     * entry point runs unprotected with this gate green. Read with `token_get_all()` rather than a
     * line-oriented strip, which is the instrument the event-bus gate settled on for the same reason.
     */
    private function codeOf(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            $code .= \is_array($token)
                ? (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token;
        }

        return $code;
    }

    /**
     * The COMMAND lines of one target: from its declaration to the first line that starts a new one, with
     * comment lines dropped.
     *
     * Dropping them is what makes the ordering assertion mean what it says. A `#` line does not start a new
     * target — correctly, since make ignores it among recipe lines — so the slice runs past the target's own
     * commands into whatever prose precedes the next declaration: measured, `db.test.prepare` spans
     * make/db.mk 39-69, of which 25 lines are comments and the tail belongs to `db.test.reset`. Both needles
     * are ordinary words, so a command deleted while a comment naming it survives would leave `strpos()`
     * comparing prose and reporting an order nothing runs in.
     */
    private function recipeOf(string $makefile, string $target): string
    {
        $start = \strpos($makefile, "\n" . $target . ':');
        $this->assertIsInt($start, \sprintf('make/db.mk declares no `%s` target.', $target));

        // Split the body only, never the declaration line itself — that line starts in column 0 too, so
        // splitting from it yields an empty block and the assertions below go vacuous.
        $rest = \substr($makefile, $start + 1);
        $newline = \strpos($rest, "\n");

        if (!\is_int($newline)) {
            return $rest;
        }

        $declaration = \substr($rest, 0, $newline + 1);
        $body = \substr($rest, $newline + 1);
        $split = \preg_split('/^(?![\t#\s])\S/m', $body, 2);

        return $this->withoutComments(
            $declaration . (\is_array($split) && [] !== $split ? $split[0] : $body),
        );
    }

    /**
     * Make comment lines, dropped whole. Anchored at the first non-blank character so a `#` inside a command
     * — a shell comment, a colour escape, a `$(subst \#,…)` — is left where it is.
     */
    private function withoutComments(string $recipe): string
    {
        $stripped = \preg_replace('/^[\t ]*#.*$/m', '', $recipe);
        $this->assertIsString($stripped, 'Stripping the recipe comments failed, so the slice is unreliable.');

        return $stripped;
    }

    /**
     * The resolution is the shared helper's; the diagnostic is this gate's, because `path()` answers `null`
     * knowing only that no candidate carried a root marker, never what the caller came for.
     *
     * The markers are root files, so this proves the root is reachable and NOT that `make/` is under it —
     * which is why the caller reads through `read()` rather than trusting the path it composes.
     *
     * An unresolvable root FAILS rather than skipping — a check that quietly does nothing when its input is
     * absent reports the same green as a real pass.
     */
    private function repoRoot(): string
    {
        return RepositoryRoot::path() ?? $this->fail(
            'The repository root is not reachable, so make/db.mk cannot be located and the prepare-order '
            . 'assertion cannot run. Inside the container it comes from the read-only `./` bind mount at '
            . '/app/repo declared in compose.dev.yaml — restore it rather than relaxing this into a skip.',
        );
    }

    /**
     * A named absence rather than a wrong answer downstream: a resolved root proves a marker file is there
     * and says nothing about the subject under it.
     *
     * The probe is `is_file()` and not `assertFileExists()`, which is `file_exists()` and therefore true for
     * a DIRECTORY — measured, `file_get_contents()` on one answers `''` rather than `false`, so both that
     * assertion and the type assertion below pass and the caller compares against an empty string, failing
     * several lines later with a message about the contents that names the wrong cause. An unreadable file
     * is the state the type assertion is left to catch, so it carries the path too.
     */
    private function read(string $path): string
    {
        $this->assertTrue(\is_file($path), \sprintf(
            'This gate reads %s and no regular file is there. Re-derive the gate against wherever it moved '
            . 'rather than deleting it.',
            $path,
        ));

        $contents = \file_get_contents($path);
        $this->assertIsString($contents, \sprintf('Could not read %s.', $path));

        return $contents;
    }
}
