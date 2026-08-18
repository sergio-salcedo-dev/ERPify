<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the part of `docs/project-context.md` that can be falsified mechanically: every version
 * it claims must be the one its manifest declares, and every version it claims must be claimed here.
 *
 * That page is read far more widely than its history suggests — 60 of the 90 installed skills name it in
 * `persistent_facts`, so it is loaded as foundational context at the start of an agent session rather than
 * consulted on demand. A stale line there is therefore not inert documentation rot: it is a false premise
 * handed to the agent before it reads any code, asserted with exactly the confidence of a true one.
 *
 * The page states normative rules as well, and those are the part no cheap check reaches. What this gate
 * does NOT cover is stated at the registry and repeated here, because a reader who takes a green for "the
 * page is true" will be wrong in the direction that has always hurt:
 *
 *   - Nothing about the third column of those tables, nor about the prose outside them, which is most of
 *     the page. Every measured drift in this page's history landed in prose — ten claims, against zero
 *     wrong version numbers.
 *   - Nothing about a version stated in a paragraph rather than in a table cell: the universe is derived
 *     from table rows, so a claim moved into prose leaves it without a word.
 *   - Nothing about the reason on an `unbound` line. It is a statement, not a proof.
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
     * The completeness direction: the registry is derived from the page, not merely compared against it.
     *
     * Without it the gate is silent about the failure that costs nothing to commit — adding a row to the
     * page and no line here — and silence is indistinguishable from agreement. Measured when this check
     * was written: 18 of the page's 33 claims had a line, and every one of the other 15 read as covered.
     */
    #[Test]
    public function everyVersionThePageClaimsIsCoveredByARegistryLine(): void
    {
        $page = $this->read($this->repoRoot() . '/' . ProjectContextVersions::PAGE);
        $tokens = \array_column(ProjectContextVersions::entriesIn($this->registryPath()), 'token');
        $uncovered = [];

        foreach (ProjectContextVersions::claimsIn($page) as $claim) {
            foreach ($tokens as $token) {
                if (ProjectContextVersions::coversClaim($token, $claim['claim'])) {
                    continue 2;
                }
            }

            $uncovered[] = \sprintf('%s:%d claims "%s"', ProjectContextVersions::PAGE, $claim['line'], $claim['claim']);
        }

        $this->assertSame([], $uncovered, \sprintf(
            "docs/project-context.md states versions that api/.project-context-versions does not:\n  %s\n"
            . 'Bind each to the manifest entry that owns it, or declare it `unbound :: <reason> => <token>` '
            . 'when nothing can. An unregistered claim is the one that goes stale unobserved.',
            \implode("\n  ", $uncovered),
        ));
    }

    /**
     * A page whose tables stopped parsing would make the check above vacuously green, and it would look
     * exactly like a page that finally claims nothing.
     */
    #[Test]
    public function thePageStillStatesVersionsForTheCompletenessCheckToFind(): void
    {
        $page = $this->read($this->repoRoot() . '/' . ProjectContextVersions::PAGE);

        $this->assertNotEmpty(
            ProjectContextVersions::claimsIn($page),
            'No version claim was extracted from the page, so completeness passes on absence.',
        );
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
