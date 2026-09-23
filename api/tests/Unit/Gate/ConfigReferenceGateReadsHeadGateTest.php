<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `php.lint.config-reference` must compare the COMMITTED `api/config/reference.php`, never the working-tree
 * copy — and this gate exists because reading the working tree made it unable to fail.
 *
 * **The mechanism, measured rather than reasoned.** FrameworkBundle's `PhpConfigReferenceDumpPass` rewrites
 * the tracked file on every debug container compile, and the lint sweep is full of targets that cause one:
 * `php.lint.doctrine` alone runs `bin/console` under `APP_ENV=test`. Against a planted drift — the
 * `monolog-bundle` 4.1.0 key removed, which is the real one that shipped:
 *
 * | Run                                               | Exit | Tracked file afterwards |
 * |---------------------------------------------------|------|-------------------------|
 * | `php.lint.config-reference` alone                 | 2    | stale (dump restores it) |
 * | `php.lint.doctrine` alone                         | 0    | **repaired, silently**   |
 * | `php.lint.config-reference` after that sibling    | **0**| repaired                 |
 *
 * The third row is the defect: a green over the identical drift. And it was not a race the sweep sometimes
 * lost — the gate waits on `php.md` and `php.cs.dry-run`, the two heaviest targets in it, while the sibling
 * is one console command, so under `-j4` the sibling wins every time. That is how #968 merged a stale
 * reference with twenty checks green, and why a person had to notice and regenerate it by hand.
 *
 * **Reading HEAD is what makes the comparison independent of the run.** The dump's own snapshot/restore
 * protects the file across its own invocation and cannot protect it from a sibling, so the fix belongs in
 * what the caller compares, not in the dump.
 *
 * **What this gate does not do.** It reads the recipe as text and proves the comparison is spelled against
 * HEAD; it does not run it, and it cannot see a future target that rewrites the file in some other way.
 * Its falsifier is the table above: plant the drift, commit it, run the sibling, run the gate.
 *
 * @internal
 */
#[CoversNothing]
final class ConfigReferenceGateReadsHeadGateTest extends TestCase
{
    private const string QUALITY_MAKEFILE = 'make/php-quality.mk';

    private const string TARGET = 'php.lint.config-reference:';

    #[Test]
    public function theGateComparesTheCommittedBlobAndNotTheWorkingTreeCopy(): void
    {
        $recipe = $this->recipe();

        $this->assertStringContainsString(
            'show "HEAD:$(CONFIG_REFERENCE_PATH)"',
            $recipe,
            'The gate must read the committed blob. A sibling of the same sweep repairs the working-tree '
            . 'copy in place, so comparing that copy is a green over a drift that is still committed.',
        );

        $this->assertSame(
            1,
            \preg_match('/^\s*diff -u (\S+) (\S+);/m', $recipe, $operands),
            'The recipe no longer holds a single `diff -u <left> <right>;` line this gate can read.',
        );

        $this->assertSame(
            '"$$committed"',
            $operands[1],
            'The left operand of the diff must be the committed blob captured from HEAD. Spelling it '
            . '$(CONFIG_REFERENCE) reads the working tree, which is the regression this gate exists for.',
        );
    }

    private function recipe(): string
    {
        $makefile = $this->read(self::QUALITY_MAKEFILE);
        $start = \mb_strpos($makefile, self::TARGET);

        $this->assertIsInt($start, \sprintf('`%s` is gone from %s.', self::TARGET, self::QUALITY_MAKEFILE));

        $rest = \mb_substr($makefile, $start);
        $end = \mb_strpos($rest, "\n\n");

        return false === $end ? $rest : \mb_substr($rest, 0, $end);
    }

    private function read(string $relative): string
    {
        $root = RepositoryRoot::path();

        $this->assertNotNull($root, \sprintf(
            'The repository root is unreachable, so %s could not be read. Inside the container it arrives '
            . 'through the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — restore it '
            . 'rather than relaxing this failure into a skip.',
            $relative,
        ));

        $path = $root . '/' . $relative;
        $this->assertFileExists($path);

        $contents = \file_get_contents($path);
        $this->assertIsString($contents, \sprintf('%s could not be read from %s.', $relative, $root));

        return $contents;
    }
}
