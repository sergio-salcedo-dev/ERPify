<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The branch logic of `scripts/deploy/restore-prod.sh` that decides whether the writers come back, driven with a fake
 * `docker` on PATH that records every Compose call and answers each one from a variable: it cannot prove the real
 * stack restores, only that — given what each step answers — the script stops, replays, overrides and restarts in the
 * order the ledger design needs.
 *
 * The cases are the ones the ledger exists for: a failed replay holds the stack (no `start`), a valid override records
 * itself and then starts, an invalid one starts nothing, a replay that passes starts every writer including
 * `scheduler_worker` when Compose lists it, and a `pg_restore` that failed (the database rolled back) restarts the
 * writers like any other failure instead of holding them.
 *
 * @internal
 */
#[CoversNothing]
final class RestoreProdRecoveryModeGateTest extends TestCase
{
    private const string FAKE_DOCKER = <<<'SH'
        #!/usr/bin/env bash
        # Records `<sub-command> <arguments>` of every `docker compose` call and answers from FAKE_* variables.
        shift
        while [[ "$1" == -* ]]; do
          case "$1" in -p | --env-file | -f) shift 2 ;; *) shift ;; esac
        done
        sub="$1"; shift
        echo "$sub $*" >> "$FAKE_CALLS"
        case "$sub" in
          ps) echo fake-container ;;
          config) printf '%s\n' "$FAKE_SERVICES" ;;
          exec)
            cat > /dev/null
            if [[ "$*" == *"pg_restore -U"* ]]; then exit "${FAKE_PGRESTORE_EXIT:-0}"; fi
            ;;
          run)
            if [[ "$*" == *replay-ledger* ]]; then exit "${FAKE_REPLAY_EXIT:-0}"; fi
            if [[ "$*" == *set-override* ]]; then
              echo 00000000-0000-4000-8000-000000000000
              exit "${FAKE_OVERRIDE_EXIT:-0}"
            fi
            ;;
        esac
        exit 0
        SH;

    private const array ORDER = ['stop', 'pg_restore', 'run replay', 'run set-override', 'start'];

    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/restore-prod-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory . '/bin', 0o777, true);
        \mkdir($this->directory . '/backups');
        \file_put_contents($this->directory . '/backups/db-S1.dump', "PGDMP\n");
        \file_put_contents($this->directory . '/env', "SERVER_NAME=erpify.local\n");
        \file_put_contents($this->directory . '/bin/docker', self::FAKE_DOCKER);
        \chmod($this->directory . '/bin/docker', 0o755);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (['bin/docker', 'backups/db-S1.dump', 'backups/.backup.lock', 'env', 'calls'] as $file) {
            if (\is_file($this->directory . '/' . $file)) {
                \unlink($this->directory . '/' . $file);
            }
        }

        foreach (['bin', 'backups', ''] as $directory) {
            if (\is_dir($this->directory . '/' . $directory)) {
                \rmdir($this->directory . '/' . $directory);
            }
        }
    }

    #[Test]
    public function aReplayThatFailsWithNoOverrideHoldsTheStack(): void
    {
        $result = $this->restore(['FAKE_REPLAY_EXIT' => '1']);

        $this->assertNotSame(0, $result['exit']);
        $this->assertNotContains('start', $this->subcommands(), 'recovery mode starts nothing');
        $this->assertNotContains('run set-override', $this->kinds());
        $this->assertStringContainsString('RECOVERY MODE', $result['output']);
    }

    #[Test]
    public function aReplayThatFailsWithAValidOverrideRecordsItAndThenStartsEveryWriter(): void
    {
        $result = $this->restore(['FAKE_REPLAY_EXIT' => '1', 'ERASURE_LEDGER_OVERRIDE' => 'ledger_lost']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame(
            ['stop', 'pg_restore', 'run replay', 'run set-override', 'start'],
            \array_values(\array_intersect($this->kinds(), self::ORDER)),
        );
        $this->assertContains(
            'run --rm --no-deps -T php php bin/console documents:erasure:set-override ledger_lost',
            $this->calls(),
        );
    }

    #[Test]
    public function anInvalidOverrideStartsNothingAndRecordsNothing(): void
    {
        $result = $this->restore(['FAKE_REPLAY_EXIT' => '1', 'ERASURE_LEDGER_OVERRIDE' => 'because']);

        $this->assertNotSame(0, $result['exit']);
        $this->assertNotContains('start', $this->subcommands());
        $this->assertNotContains('run set-override', $this->kinds());
    }

    #[Test]
    public function anOverrideThatCannotBeRecordedStartsNothing(): void
    {
        $result = $this->restore([
            'FAKE_REPLAY_EXIT' => '1',
            'ERASURE_LEDGER_OVERRIDE' => 'ledger_corrupt',
            'FAKE_OVERRIDE_EXIT' => '1',
        ]);

        $this->assertNotSame(0, $result['exit']);
        $this->assertNotContains('start', $this->subcommands());
    }

    #[Test]
    public function aReplayThatPassesStartsEveryWriterIncludingTheSchedulerWhenComposeListsIt(): void
    {
        $result = $this->restore(['FAKE_SERVICES' => "php\nmessenger_worker\nscheduler_worker\ndatabase"]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertContains('start php messenger_worker scheduler_worker', $this->calls());
        $this->assertContains('stop php messenger_worker scheduler_worker', $this->calls());
    }

    #[Test]
    public function aStackWithNoSchedulerStopsAndStartsOnlyTheTwoWriters(): void
    {
        $result = $this->restore(['FAKE_SERVICES' => "php\nmessenger_worker\ndatabase"]);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertContains('start php messenger_worker', $this->calls());
    }

    #[Test]
    public function aPgRestoreThatFailedRestartsTheWritersBecauseTheDatabaseRolledBack(): void
    {
        $result = $this->restore(['FAKE_PGRESTORE_EXIT' => '1']);

        $this->assertNotSame(0, $result['exit']);
        $this->assertContains('start php messenger_worker scheduler_worker', $this->calls());
        $this->assertNotContains('run replay', $this->kinds(), 'nothing to replay over a rolled-back database');
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{exit: int, output: string}
     */
    private function restore(array $environment): array
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull($root, 'The repository root is unreachable (/app/repo bind mount).');

        $process = new Process(
            ['bash', $root . '/scripts/deploy/restore-prod.sh', 'S1'],
            null,
            $environment + [
                'PATH' => $this->directory . '/bin:' . (\getenv('PATH') ?: '/usr/bin:/bin'),
                'PROD_ENV_FILE' => $this->directory . '/env',
                'BACKUP_DIR' => $this->directory . '/backups',
                'COMPOSE_PROJECT_NAME' => 'restore-test',
                'RESTORE_YES' => '1',
                'FAKE_CALLS' => $this->directory . '/calls',
                'FAKE_SERVICES' => "php\nmessenger_worker\nscheduler_worker\ndatabase",
            ],
            null,
            60,
        );
        $process->run();

        return [
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }

    /**
     * @return list<string> one entry per Compose call: the sub-command and its arguments
     */
    private function calls(): array
    {
        $file = $this->directory . '/calls';

        return \is_file($file) ? \array_values(\array_filter(\explode("\n", (string) \file_get_contents($file)))) : [];
    }

    /**
     * @return list<string> the sub-command of each call
     */
    private function subcommands(): array
    {
        return \array_map(static fn (string $call): string => \explode(' ', $call)[0], $this->calls());
    }

    /**
     * @return list<string> a name for each call that matters, in order
     */
    private function kinds(): array
    {
        $kinds = [];

        foreach ($this->calls() as $call) {
            $kinds[] = match (true) {
                \str_contains($call, 'pg_restore -U') => 'pg_restore',
                \str_contains($call, 'replay-ledger') => 'run replay',
                \str_contains($call, 'set-override') => 'run set-override',
                \str_starts_with($call, 'stop ') => 'stop',
                \str_starts_with($call, 'start ') => 'start',
                default => $call,
            };
        }

        return $kinds;
    }
}
