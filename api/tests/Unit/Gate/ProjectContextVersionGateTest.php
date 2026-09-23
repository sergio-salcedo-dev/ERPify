<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ProjectContextVersions;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the part of `docs/project-context.md` that can be falsified mechanically: every version
 * it claims must be the one its manifest declares, and every version it claims must be claimed here.
 *
 * That page is read more widely than its history suggests, though the route changed under it: until BMAD
 * 6.12 every skill's `customize.toml` shipped a `persistent_facts` entry globbing the page, and 62 loaded
 * the page outright, where 6.12 ships that array empty — measured on the current tree, 0 of the 75 installed
 * skills carry a non-empty one, and 11 name the page directly. A stale line there is therefore not inert
 * documentation rot: it is a false premise handed to the agent before it reads any code, asserted with
 * exactly the confidence of a true one.
 *
 * The numbers are what a cheap check can falsify, and they have needed it: fourteen second-column version
 * numbers have been corrected over the page's history, twelve of them in one commit. The page also states
 * normative rules, which no cheap check reaches. What this gate does NOT cover is stated at the registry
 * and repeated here, because a reader who takes a green for "the page is true" will be wrong:
 *
 *   - Nothing about the third column of those tables, nor about the prose outside them, which is most of
 *     the page and still held two provable falsehoods when this gate was written.
 *   - Nothing about a version stated in a paragraph rather than in a table cell, nor one glued to its
 *     subject by `:` or `/` — `node:26-trixie` is stated in a second column and is not in the universe.
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
     * The other half of deriving the universe, and the one that keeps the derivation honest.
     *
     * `assertNotEmpty` on the extraction only fires at ZERO claims, so a page edit that changes one table's
     * column layout drops that table's claims and everything stays green — measured: adding a leading
     * column to the PWA table alone takes the universe from 33 to 18, and the fifteen orphaned lines keep
     * passing because their tokens are still page TEXT, which is all the staleness leg reads. From then on
     * a row added to that table needs no registry line, which is the failure this gate exists to close.
     *
     * Requiring every line to cover a live claim reds that edit on the spot, and it subsumes the weaker
     * boundary the staleness leg lacks: nine tokens occur more than once on the page, so deleting their
     * cell would not move `str_contains`, but it does move this.
     */
    #[Test]
    public function everyRegistryLineStillCoversAClaimThePageMakes(): void
    {
        $page = $this->read($this->repoRoot() . '/' . ProjectContextVersions::PAGE);
        $claims = \array_column(ProjectContextVersions::claimsIn($page), 'claim');
        $stranded = [];

        foreach (ProjectContextVersions::entriesIn($this->registryPath()) as $entry) {
            foreach ($claims as $claim) {
                if (ProjectContextVersions::coversClaim($entry['token'], $claim)) {
                    continue 2;
                }
            }

            $stranded[] = $entry['token'];
        }

        $this->assertSame([], $stranded, \sprintf(
            "api/.project-context-versions binds versions the page's tables no longer claim:\n  %s\n"
            . 'Either the claim moved out of a table cell — in which case the extraction has stopped seeing '
            . 'its whole table and the completeness half is now vacuous — or the claim is gone and so should '
            . 'the line.',
            \implode("\n  ", $stranded),
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
     * The architecture pages name the same technologies and must not restate their versions.
     *
     * Both carried a `Version` column until this gate, and it drifted precisely because the gate could not
     * see it: those tables put the product in one column and the number in the next, so the name-adjacent
     * extraction {@see ProjectContextVersions::claimsIn()} performs found nothing there. Seven numbers in
     * `architecture-pwa.md` were falsified by a single dependency batch with every check green.
     *
     * Widening the extractor was measured and rejected. Pairing the two columns positionally has to split
     * them on `/`, which is also inside the package names — `@base-ui/react`, `symfony/uid`,
     * `@testing-library/react` — so legitimate rows came back mismatched, and prose tables elsewhere on the
     * page parsed as version claims. What is refused instead is the duplication: one page owns the numbers
     * and this one points at it.
     *
     * **The check is scoped to table cells, and that is a limit rather than an oversight.** Extending it to
     * the whole text was measured too: 93 matches on one page and 21 on the other, almost all HTTP status
     * codes (`answers 400`, `a 422`), RFC numbers and `Level 1`. Prose on these pages may still name a
     * version, so the convention beside this gate is that prose names a MAJOR — stable for years, and the
     * idiom `docs/project-context.md` already uses for "Doctrine ORM 3 / DBAL 4".
     */
    #[Test]
    public function testTheArchitecturePagesRestateNoVersion(): void
    {
        $restated = [];

        foreach (ProjectContextVersions::MIRROR_PAGES as $page) {
            foreach (ProjectContextVersions::claimsIn($this->read($this->repoRoot() . '/' . $page)) as $claim) {
                $restated[] = \sprintf('%s:%d restates "%s"', $page, $claim['line'], $claim['claim']);
            }
        }

        $this->assertSame([], $restated, \sprintf(
            "A version returned to an architecture page's table. %s owns the numbers and is bound to the "
            . 'manifests; a copy here is compared against nothing and drifts in silence, which is how seven '
            . "of them went stale unnoticed. State the technology and let the reader follow the link:\n%s",
            ProjectContextVersions::PAGE,
            \implode("\n", $restated),
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
        return \dirname(__DIR__, 3) . '/' . ProjectContextVersions::REGISTRY;
    }

    /**
     * The subject sits outside the `./api` build context, so in the container it arrives only through the
     * read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`. Missing it is a failure and
     * never a skip: a gate that passes when it cannot see what it compares reports an agreement it never
     * checked.
     */
    private function repoRoot(): string
    {
        return RepositoryRoot::path() ?? $this->fail(
            'docs/project-context.md and the dependency manifests are unreachable, so this gate cannot read a single '
            . 'claim. Inside the container it comes from the read-only `./` bind mount at /app/repo declared in '
            . 'compose.dev.yaml — restore it rather than relaxing this failure into a skip.',
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
