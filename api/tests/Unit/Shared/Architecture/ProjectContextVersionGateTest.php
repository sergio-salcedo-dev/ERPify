<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the only part of `docs/project-context.md` that can be falsified mechanically: every
 * version it claims must be the one its manifest declares.
 *
 * That page is read far more widely than its history suggests — 60 of the installed BMAD skills name it in
 * `persistent_facts`, so it is loaded as foundational context at the start of an agent session rather than
 * consulted on demand. A stale line there is therefore not inert documentation rot: it is a false premise
 * handed to the agent before it reads any code, asserted with exactly the confidence of a true one.
 *
 * The page was cut down to versions, training-cutoff traps and pointers precisely so that this gate could
 * cover most of what remains. What it does NOT cover is stated at the registry and repeated here because a
 * reader who takes a green for "the page is true" will be wrong in the direction that has always hurt:
 *
 *   - Nothing about the third column of those tables, which is prose. Every measured drift in this page's
 *     history landed there — ten claims, against zero wrong version numbers.
 *   - Nothing about a version the page states that has no registry line. The universe cannot be derived
 *     (a manifest holds hundreds of entries and the page names a dozen), so completeness is a review
 *     obligation. That is a real hole and naming it is the only control on it.
 *   - Nothing about anything pinned by digest rather than by manifest.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextVersionGateTest extends TestCase
{
    #[Test]
    public function everyClaimedVersionMatchesItsManifestAndStillAppearsOnThePage(): void
    {
        $repoRoot = $this->repoRoot();
        $page = $this->read($repoRoot . '/' . ProjectContextVersions::PAGE);
        $defects = [];

        foreach (ProjectContextVersions::entriesIn($this->registryPath()) as $entry) {
            $defect = ProjectContextVersions::defectIn($repoRoot, $entry, $page);

            if (null !== $defect) {
                $defects[] = $defect;
            }
        }

        $this->assertSame([], $defects, \sprintf(
            "docs/project-context.md disagrees with the manifests it describes:\n  %s\n"
            . 'Fix the page and api/.project-context-versions together — that page is loaded as foundational '
            . 'context by most BMAD agent activations, so a wrong version is asserted to the agent before it '
            . 'reads a line of code.',
            \implode("\n  ", $defects),
        ));
    }

    /**
     * A silent empty registry, or a page that has quietly become a stub, would make the check above
     * vacuously green — the exact shape this gate exists to refuse elsewhere.
     */
    #[Test]
    public function theGateHasARegistryAndAPageToCheck(): void
    {
        $entries = ProjectContextVersions::entriesIn($this->registryPath());

        $this->assertNotEmpty($entries, 'The version registry declares nothing, so this gate checks nothing.');

        $page = $this->read($this->repoRoot() . '/' . ProjectContextVersions::PAGE);

        $this->assertNotEmpty($page, 'docs/project-context.md is empty, so every staleness check passes on absence.');
    }

    private function registryPath(): string
    {
        return \dirname(__DIR__, 4) . '/' . ProjectContextVersions::REGISTRY;
    }

    /**
     * The PWA manifest and the page both sit outside the `./api` build context, so in the container they
     * arrive only through the read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`.
     * Missing it is a failure, not a skip: a gate that passes when it cannot see what it compares reports
     * an agreement it never checked.
     */
    private function repoRoot(): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_dir($candidate . '/pwa/src')) {
                return $candidate;
            }
        }

        $this->fail(
            'The repository root is not reachable, so this gate cannot check anything. Inside the container '
            . 'it comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — '
            . 'restore it rather than relaxing this failure into a skip.',
        );
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path, \sprintf(
            'This gate compares against %s and it is not there. Re-derive the gate against wherever it '
            . 'moved rather than deleting it.',
            $path,
        ));

        $contents = \file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
