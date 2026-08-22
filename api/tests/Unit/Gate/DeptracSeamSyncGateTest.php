<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Static gate keeping the two published-cross-context-seam lists in lockstep:
 *
 *   - api/.bounded-context-allowlist — `<importer path.php> => <Imported\Fqcn>`,
 *     the source of truth consumed by BoundedContextGateTest (the text-walk gate).
 *   - tools/deptrac/deptrac.yaml `skip_violations` — `<Importer\Fqcn>: [<Imported\Fqcn>]`,
 *     the same seams expressed for the AST-aware deptrac gate (defence-in-depth).
 *
 * deptrac re-dumps the whole active `skip_violations` on every baseline regen, and
 * the two files are hand-maintained, so they can silently drift: a seam present in
 * one but not the other means deptrac would either flag a blessed cross-context
 * import as a violation (false positive) or grandfather a seam the allowlist no
 * longer blesses. This gate fails the build the moment they diverge — the comment
 * "Keep the two lists in sync" in deptrac.yaml is now machine-enforced.
 *
 * Scope: only the concrete per-file seams (`path => Fqcn`) map onto deptrac's
 * per-class `skip_violations`. The allowlist's coarser forms — a whole-file
 * exemption (`path` with no `=>`) and a global published seam (`* => Fqcn`) — cannot
 * be expressed as a per-class skip, so deptrac models them differently (or not at
 * all). If one ever appears, this gate fails loudly so the divergence is a
 * conscious decision rather than an invisible hole.
 *
 * @internal
 */
#[CoversNothing]
final class DeptracSeamSyncGateTest extends TestCase
{
    public function testDeptracSkipViolationsMatchBoundedContextAllowlistSeams(): void
    {
        $allowlistSeams = $this->seamsFromAllowlist();
        $deptracSeams = $this->seamsFromDeptracConfig();

        \sort($allowlistSeams);
        \sort($deptracSeams);

        $this->assertSame(
            $allowlistSeams,
            $deptracSeams,
            'Published cross-context seams have drifted between api/.bounded-context-allowlist '
            . 'and skip_violations in tools/deptrac/deptrac.yaml. The two lists must stay in sync '
            . "(add/remove the seam in BOTH). Allowlist seams:\n  "
            . \implode("\n  ", $allowlistSeams)
            . "\ndeptrac skip_violations:\n  "
            . \implode("\n  ", $deptracSeams),
        );
    }

    public function testAllowlistUsesNoFormDeptracCannotMirror(): void
    {
        // Whole-file exemptions and global (`* =>`) seams have no per-class
        // skip_violations equivalent. None exist today; if one is added, the
        // sync gate above can no longer guarantee parity, so force a conscious
        // decision here instead of letting deptrac silently diverge.
        $unmirrorable = $this->unmirrorableAllowlistEntries();

        $this->assertSame(
            [],
            $unmirrorable,
            "api/.bounded-context-allowlist contains entries deptrac's per-class skip_violations "
            . 'cannot mirror (whole-file exemption or `* =>` global seam). Model these explicitly '
            . "in tools/deptrac/deptrac.yaml and update this gate:\n  "
            . \implode("\n  ", $unmirrorable),
        );
    }

    /**
     * Concrete per-file seams from the allowlist, as `Importer\Fqcn => Imported\Fqcn`.
     *
     * @return list<string>
     */
    private function seamsFromAllowlist(): array
    {
        $seams = [];

        foreach ($this->allowlistLines() as $trimmed) {
            if (!\str_contains($trimmed, '=>')) {
                continue;
            }

            $parts = \explode('=>', $trimmed, 2);
            $left = \trim($parts[0]);
            $right = \trim($parts[1]);

            if ('*' === $left) {
                continue;
            }

            $seams[] = $this->pathToFqcn($left) . ' => ' . $right;
        }

        return $seams;
    }

    /**
     * Allowlist forms deptrac's per-class skip_violations cannot express:
     * whole-file exemptions (no `=>`) and global seams (`* =>`).
     *
     * @return list<string>
     */
    private function unmirrorableAllowlistEntries(): array
    {
        $entries = [];

        foreach ($this->allowlistLines() as $trimmed) {
            if (!\str_contains($trimmed, '=>')) {
                $entries[] = $trimmed;

                continue;
            }

            if (\str_starts_with($trimmed, '*')) {
                $entries[] = $trimmed;
            }
        }

        return $entries;
    }

    /**
     * Non-comment, non-blank lines of api/.bounded-context-allowlist.
     *
     * @return list<string>
     */
    private function allowlistLines(): array
    {
        $path = $this->apiRoot() . '/.bounded-context-allowlist';
        $raw = \is_file($path) ? \file_get_contents($path) : false;

        if (false === $raw) {
            $this->fail('Could not read api/.bounded-context-allowlist.');
        }

        $lines = [];

        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (\str_starts_with($trimmed, '#')) {
                continue;
            }

            $lines[] = $trimmed;
        }

        return $lines;
    }

    /**
     * Seams declared in deptrac.yaml's own `skip_violations`, as
     * `Importer\Fqcn => Imported\Fqcn`. Parsing the config file directly (not the
     * imported baseline) yields exactly the published seams — the grandfathered
     * inner-layer debt lives in deptrac.baseline.yaml and is intentionally excluded.
     *
     * @return list<string>
     */
    private function seamsFromDeptracConfig(): array
    {
        $path = $this->apiRoot() . '/tools/deptrac/deptrac.yaml';
        /** @var array{deptrac?: array{skip_violations?: array<string, list<string>>}} $config */
        $config = Yaml::parseFile($path);
        $skipViolations = $config['deptrac']['skip_violations'] ?? [];

        $seams = [];

        foreach ($skipViolations as $importer => $targets) {
            foreach ($targets as $target) {
                $seams[] = $importer . ' => ' . $target;
            }
        }

        return $seams;
    }

    /**
     * Maps a PSR-4 source path (`src/Foo/Bar.php`) to its FQCN (`Erpify\Foo\Bar`).
     */
    private function pathToFqcn(string $relativePath): string
    {
        $withoutPrefix = \preg_replace('#^src/#', '', $relativePath) ?? $relativePath;
        $withoutExtension = \preg_replace('#\.php$#', '', $withoutPrefix) ?? $withoutPrefix;

        return 'Erpify\\' . \str_replace('/', '\\', $withoutExtension);
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
