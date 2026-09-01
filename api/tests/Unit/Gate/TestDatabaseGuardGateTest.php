<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Database\RefuseRuntimeDatabaseGuard;
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
            $source = \file_get_contents($this->apiRoot() . '/' . $entryPoint);
            $this->assertIsString($source);
            $this->assertStringContainsString(
                'RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(',
                $source,
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
        $recipe = \file_get_contents($this->repositoryRoot() . '/make/db.mk');
        $this->assertIsString($recipe);

        // Scoped to the target's own recipe: `doctrine:migrations:migrate` also appears in `db.migrate`, far
        // earlier in the file, and searching the whole text compares against that one instead.
        $recipe = $this->recipeOf($recipe, 'db.test.prepare');

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
     * Where `make/` is reachable from, which differs by how the suite is invoked: with the whole checkout
     * present it sits beside `api/`, while inside the dev container `/app` holds only `api/`, `public/` and
     * the mounts, so it arrives through the read-only root bind mount at `/app/repo`.
     *
     * An unresolvable directory FAILS rather than skipping — a check that quietly does nothing when its input
     * is absent reports the same green as a real pass.
     */
    /**
     * The recipe lines of one target: from its declaration to the first line that starts a new one.
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

        return $declaration . (\is_array($split) && [] !== $split ? $split[0] : $body);
    }

    private function repositoryRoot(): string
    {
        $apiRoot = $this->apiRoot();

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_file($candidate . '/make/db.mk')) {
                return $candidate;
            }
        }

        $this->fail(
            'make/db.mk is not reachable, so the prepare-order assertion cannot run. Inside the container it '
            . 'comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — restore '
            . 'it rather than relaxing this failure into a skip.',
        );
    }
}
