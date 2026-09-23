<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Executing gate over `scripts/bmad-skills-sync.sh`, the one command in this repository that deletes a
 * directory tree whose contents may exist nowhere else.
 *
 * **Why it runs the script instead of reading it.** The sync's only automated check was `make shell.lint`,
 * and shellcheck is a linter: it observes no classification, no removal, no backup and no restore. Both
 * defects this gate pins were measured on the shipped version, each with every gate in the repository green
 * and the `Shell (ShellCheck)` job passing:
 *
 *  - a directory the source lacked was deleted with **exit 0** whenever git had history at the same path,
 *    which separates PATHS rather than AUTHORS, so hand-written content at a retired skill's name went with
 *    it. The tracked tree that arbitration relied on is gone — every skill root is installer output now —
 *    so the rule is conservative: anything the source lacks stops the run, whatever it looks like;
 *  - a copy that failed after the delete loop aborted under `set -e` **before** the verification loop, so the
 *    run exited **1** over a gutted tree — and 1 is the code the script's own contract gives to outcomes that
 *    touch nothing, so a caller reads a half-written tree as a safe no-op.
 *
 * A source-reading gate cannot see either: the first is a property of what git answers for a given path, the
 * second of how `set -e` interacts with an unguarded command. So each case below builds a throwaway git
 * checkout, runs the real script against it with `--root`, and asserts the exit code together with the state
 * of the tree afterwards.
 *
 * **What a green does not prove.** Nothing about the script's behaviour on the real skill roots, which these
 * fixtures only imitate; nothing about concurrency beyond the single lock acquisition; and nothing about a
 * hand-written skill whose name does not begin with `bmad-`, which the glob excludes from the sync's universe
 * altogether and which is therefore protected by that glob rather than by anything asserted here.
 *
 * @internal
 */
#[CoversNothing]
final class BmadSkillsSyncGuardrailGateTest extends TestCase
{
    private string $fixture = '';

    protected function tearDown(): void
    {
        if ('' !== $this->fixture && \is_dir($this->fixture)) {
            (new Process(['rm', '-rf', $this->fixture]))->run();
        }

        $this->fixture = '';
    }

    #[Test]
    public function anythingTheSourceLacksStopsTheRun(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad('bmad-retired');
        $this->write('.claude/skills/bmad-retired/SKILL.md', "written by a person, held nowhere else\n");

        $run = $this->sync();

        $this->assertSame(1, $run->getExitCode(), $this->explain($run));
        $this->assertDirectoryExists($this->fixture . '/.claude/skills/bmad-retired');
        $this->assertStringContainsString('absent from', $run->getErrorOutput());
    }

    #[Test]
    public function anExtraThatLooksExactlyLikeInstallerOutputIsRefusedToo(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad('bmad-retired');
        $this->write('.claude/skills/bmad-retired/SKILL.md', "retired\n");

        $run = $this->sync();

        // Byte-identical to what the installer shipped, and still refused: that is the whole
        // point of the conservative rule. Nothing left in the tree can tell this apart from a
        // person's own file, so the run stops rather than guessing in the unrecoverable direction.
        $this->assertSame(1, $run->getExitCode(), $this->explain($run));
        $this->assertDirectoryExists($this->fixture . '/.claude/skills/bmad-retired');
    }

    #[Test]
    public function forceRemovesHandWrittenContentOnlyAfterArchivingIt(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad('bmad-retired');
        $this->write('.claude/skills/bmad-retired/SKILL.md', "written by a person\n");

        $run = $this->sync(['--force']);

        $this->assertSame(0, $run->getExitCode(), $this->explain($run));
        $this->assertDirectoryDoesNotExist($this->fixture . '/.claude/skills/bmad-retired');

        $archived = \glob($this->fixture . '/tmp/bmad-skills-backup-*.tar.gz') ?: [];
        $this->assertCount(1, $archived, 'the run must leave exactly one backup');

        $listing = new Process(['tar', 'tzf', $archived[0]]);
        $listing->mustRun();

        $recoverable = 'the removed hand-written file must be recoverable from the backup';
        $this->assertStringContainsString('.claude/skills/bmad-retired/SKILL.md', $listing->getOutput(), $recoverable);
    }

    #[Test]
    public function aDryRunIsNeverBlockedByTheGuardrailItDoesNotTrigger(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad('bmad-retired');
        $this->write('.claude/skills/bmad-retired/SKILL.md', "written by a person\n");

        $run = $this->sync(['--dry-run']);

        $this->assertSame(1, $run->getExitCode(), $this->explain($run));
        $this->assertStringContainsString('unknown', $run->getOutput());
        $this->assertDirectoryExists($this->fixture . '/.claude/skills/bmad-retired');
    }

    #[Test]
    public function aNonDirectoryEntryIsRefusedBeforeAnythingIsRemoved(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad(null);
        $this->write('.claude/skills/bmad-beta', "a file, not a directory\n");

        $run = $this->sync();

        $this->assertSame(2, $run->getExitCode(), $this->explain($run));
        $untouched = $this->fixture . '/.claude/skills/bmad-alpha';
        $this->assertDirectoryExists($untouched, 'the refusal must precede the delete loop');
    }

    #[Test]
    public function aFailureAfterTheBackupRestoresTheTreeAndExitsTwo(): void
    {
        $this->givenACheckoutWhereTheInstallerOnceHad(null);

        // A dangling symlink is copied happily by `cp -a` and then makes `diff` exit 2, which is the one
        // deterministic way to reach the post-backup failure path without depending on a file mode — the
        // container runs as root, where an unreadable directory is not unreadable. There is no such symlink
        // in either real skill tree (measured: zero), so this is a synthetic pathology chosen for being
        // reproducible, not a live risk.
        \symlink('/nonexistent/target', $this->fixture . '/.agent/skills/bmad-alpha/dangling');
        $this->write('.claude/skills/bmad-alpha/SKILL.md', "drifted\n");

        $run = $this->sync();

        $distinct = 'a half-written tree must never share an exit code with "nothing was touched"';
        $this->assertSame(2, $run->getExitCode(), $distinct . $this->explain($run));
        $restored = $this->fixture . '/.claude/skills/bmad-alpha/SKILL.md';
        $this->assertFileExists($restored, 'the restore must put back what the delete loop removed');
        $this->assertStringContainsString('restored', $run->getErrorOutput());
    }

    /**
     * The sibling of the case above, reaching the restore through the ERR trap rather than the verify loop's
     * explicit call. It needs a copy that genuinely fails, and the only portable way to arrange one is a
     * source directory the process cannot read — which root can, so under root this case has nothing to
     * measure and says so instead of passing vacuously. Kept beside the other because removing the trap
     * leaves the verify-loop case green: each branch pins one of them and neither pins both.
     */
    #[Test]
    public function aCopyThatFailsReachesTheSameRestoreThroughTheTrap(): void
    {
        if (0 === \posix_geteuid()) {
            $this->markTestSkipped('root reads an unreadable directory, so the copy cannot be made to fail here');
        }

        $this->givenACheckoutWhereTheInstallerOnceHad(null);
        $this->write('.agent/skills/bmad-alpha/nested/SKILL.md', "alpha\n");
        $this->commitFixture('nest alpha');
        $seed = ['cp', '-a', $this->fixture . '/.agent/skills/bmad-alpha', $this->fixture . '/.claude/skills/'];
        (new Process($seed))->mustRun();
        $this->write('.claude/skills/bmad-alpha/nested/SKILL.md', "drifted\n");

        \chmod($this->fixture . '/.agent/skills/bmad-alpha/nested', 0o000);
        $run = $this->sync();
        \chmod($this->fixture . '/.agent/skills/bmad-alpha/nested', 0o755);

        $distinct = 'a copy that aborted must not exit with the code that means nothing was touched';
        $this->assertSame(2, $run->getExitCode(), $distinct . $this->explain($run));
        $restored = $this->fixture . '/.claude/skills/bmad-alpha/nested/SKILL.md';
        $this->assertFileExists($restored, 'the trap must restore what the delete loop removed');
    }

    /**
     * A checkout holding one live skill (`bmad-alpha`) and, optionally, one the installer has since removed —
     * committed and then deleted, so git has history at that path and no current entry, which is the exact
     * shape the classifier has to read.
     */
    private function givenACheckoutWhereTheInstallerOnceHad(?string $retired): void
    {
        $this->fixture = (string) \tempnam(\sys_get_temp_dir(), 'bmad-sync-gate-');
        \unlink($this->fixture);
        \mkdir($this->fixture, 0o755, true);

        $this->write('.agent/skills/bmad-alpha/SKILL.md', "alpha\n");

        if (null !== $retired) {
            $this->write('.agent/skills/' . $retired . '/SKILL.md', "retired\n");
        }

        $this->git(['init', '-q', '.']);
        $this->git(['config', 'user.email', 'gate@example.test']);
        $this->git(['config', 'user.name', 'gate']);
        $this->commitFixture('install');

        if (null !== $retired) {
            $this->git(['rm', '-rq', '.agent/skills/' . $retired]);
            $this->commitFixture('retire ' . $retired);
        }

        \mkdir($this->fixture . '/.claude/skills', 0o755, true);
        $seed = ['cp', '-a', $this->fixture . '/.agent/skills/bmad-alpha', $this->fixture . '/.claude/skills/'];
        (new Process($seed))->mustRun();
    }

    /** @param list<string> $flags */
    private function sync(array $flags = []): Process
    {
        $script = RepositoryRoot::path() . '/scripts/bmad-skills-sync.sh';
        $this->assertFileExists($script);

        $run = new Process([$script, '--root', $this->fixture, ...$flags]);
        $run->run();

        return $run;
    }

    private function commitFixture(string $message): void
    {
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', $message]);
    }

    /** @param list<string> $args */
    private function git(array $args): void
    {
        (new Process(['git', ...$args], $this->fixture))->mustRun();
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->fixture . '/' . $relative;
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            \mkdir($dir, 0o755, true);
        }

        \file_put_contents($path, $contents);
    }

    private function explain(Process $run): string
    {
        return \sprintf("\nstdout: %s\nstderr: %s", $run->getOutput(), $run->getErrorOutput());
    }
}
