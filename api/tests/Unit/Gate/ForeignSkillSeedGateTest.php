<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Executing gate over `scripts/seed-foreign-skills.sh`, which `make worktree.create` calls to give a new
 * worktree the foreign skills (`bmad-loop-*`, TEA 3's `bmad-tea*` and `bmod-tea`) only the primary checkout's
 * `.claude/skills` holds.
 *
 * **Why it runs the script.** Its failure is silent where it matters: a worktree missing a skill answers
 * `Unknown command: /bmad-loop-sweep` and idles to its session timeout while `bmad-loop validate` reports
 * ok. The two shapes that produced that were an all-or-nothing guard, which skipped every skill once the
 * worktree held any one of them, and a copy whose failure still printed success. Each case below builds
 * throwaway directories, runs the real script and asserts its exit code with the tree it leaves.
 *
 * **What a green does not prove.** Nothing about the Make recipe around the script, which calls it and
 * tolerates its failure; nothing about the real skill roots, which these fixtures only imitate; and the
 * failed copy is staged by making `.claude/skills` a file, because the suite runs as root and a permission
 * bit would not stop it.
 *
 * @internal
 */
#[CoversNothing]
final class ForeignSkillSeedGateTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $fixture = \sys_get_temp_dir() . '/foreign-skill-seed-' . \bin2hex(\random_bytes(6));
        \mkdir($fixture . '/main/.claude/skills', 0o755, true);
        \mkdir($fixture . '/worktree', 0o755, true);
        $this->fixture = $fixture;
    }

    protected function tearDown(): void
    {
        if ('' !== $this->fixture && \is_dir($this->fixture)) {
            (new Process(['rm', '-rf', $this->fixture]))->run();
        }

        $this->fixture = '';
    }

    #[Test]
    public function aWorktreeHoldingOneSkillStillReceivesTheOthersAndKeepsItsOwn(): void
    {
        $this->write('main/.claude/skills/bmad-loop-sweep/SKILL.md', "sweep from the primary\n");
        $this->write('main/.claude/skills/bmad-loop-resolve/SKILL.md', "resolve from the primary\n");
        $this->write('worktree/.claude/skills/bmad-loop-resolve/SKILL.md', "the worktree's own copy\n");

        $run = $this->seed();

        $this->assertSame(0, $run->getExitCode(), $this->explain($run));
        $this->assertSame("sweep from the primary\n", $this->read('worktree/.claude/skills/bmad-loop-sweep/SKILL.md'));
        $this->assertSame(
            "the worktree's own copy\n",
            $this->read('worktree/.claude/skills/bmad-loop-resolve/SKILL.md'),
        );
    }

    /**
     * TEA 3 reads its knowledge base from the sibling `bmod-tea`, so a worktree that received the
     * `bmad-tea*` skills without it would start every TEA workflow by asking to reinstall it.
     * `bmad-teach-me-testing` is TEA 1's installer output and reaches a worktree through the
     * `bmad-*` seed, so this one must not copy it.
     */
    #[Test]
    public function teaThreeSkillsAndTheirKnowledgeBaseAreSeededButTeaOnesAreNot(): void
    {
        $this->write('main/.claude/skills/bmad-tea/SKILL.md', "tea agent\n");
        $this->write('main/.claude/skills/bmad-tea-trace/SKILL.md', "tea trace\n");
        $this->write('main/.claude/skills/bmod-tea/knowledge/tea-index.csv', "id,path\n");
        $this->write('main/.claude/skills/bmad-teach-me-testing/SKILL.md', "tea 1, installer output\n");

        $run = $this->seed();

        $this->assertSame(0, $run->getExitCode(), $this->explain($run));
        $this->assertSame("tea agent\n", $this->read('worktree/.claude/skills/bmad-tea/SKILL.md'));
        $this->assertSame("tea trace\n", $this->read('worktree/.claude/skills/bmad-tea-trace/SKILL.md'));
        $this->assertSame("id,path\n", $this->read('worktree/.claude/skills/bmod-tea/knowledge/tea-index.csv'));
        $this->assertDirectoryDoesNotExist($this->fixture . '/worktree/.claude/skills/bmad-teach-me-testing');
    }

    #[Test]
    public function aCopyThatFailsIsReportedAndExitsOneInsteadOfPrintingSuccess(): void
    {
        $this->write('main/.claude/skills/bmad-loop-sweep/SKILL.md', "sweep from the primary\n");
        $this->write('worktree/.claude/skills', "a file where the skills directory belongs\n");

        $run = $this->seed();

        $this->assertSame(1, $run->getExitCode(), $this->explain($run));
        $this->assertStringContainsString('! could not seed .claude/skills/bmad-loop-sweep', $run->getErrorOutput());
        $this->assertStringNotContainsString('seeded', $run->getOutput(), $this->explain($run));
    }

    #[Test]
    public function aPrimaryWithoutForeignSkillsSeedsNothingAndSucceeds(): void
    {
        $this->write('main/.claude/skills/bmad-help/SKILL.md', "installer output, seeded from .agent/skills instead\n");

        $run = $this->seed();

        $this->assertSame(0, $run->getExitCode(), $this->explain($run));
        $this->assertSame('', $run->getOutput());
        $this->assertDirectoryDoesNotExist($this->fixture . '/worktree/.claude');
    }

    private function seed(): Process
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull($root, 'The repository root is not reachable, so the script cannot be run.');

        $script = $root . '/scripts/seed-foreign-skills.sh';
        $this->assertFileExists($script);

        $run = new Process([$script, $this->fixture . '/main', $this->fixture . '/worktree']);
        $run->run();

        return $run;
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

    private function read(string $relative): string
    {
        $path = $this->fixture . '/' . $relative;
        $this->assertFileIsReadable($path);

        $contents = \file_get_contents($path);
        $this->assertIsString($contents, \sprintf('%s could not be read.', $relative));

        return $contents;
    }

    private function explain(Process $run): string
    {
        return \sprintf("\nstdout: %s\nstderr: %s", $run->getOutput(), $run->getErrorOutput());
    }
}
