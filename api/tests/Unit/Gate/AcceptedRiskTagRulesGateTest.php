<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\AcceptedRiskTag;
use Erpify\Tests\Support\AcceptedRiskTags;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifies the grammar, paragraph-segmentation and content-floor rules behind the accepted-risk gate
 * against synthetic fixtures under {@see Fixture\AcceptedRiskTags}, so
 * {@see AcceptedRiskTagGateTest} can trust them on the real tree without re-deriving them.
 *
 * Per docs/rules/testing.md ("assert the seed before asserting the absence"): every negative assertion
 * below sits beside a fixture that PROVES the corresponding violation is caught by the same machinery, so
 * a green suite here is never green because nothing was ever tested against a real violation.
 *
 * @internal test support
 */
#[CoversNothing]
final class AcceptedRiskTagRulesGateTest extends TestCase
{
    #[Test]
    public function aTagCoLocatedWithRationaleInTheSameParagraphPasses(): void
    {
        $path = $this->fixture('WellFormedTagFixture.php');
        $tags = AcceptedRiskTags::scanFile($path);

        $this->assertCount(1, $tags);
        $this->assertSame(500, $tags[0]->issueNumber);

        $paragraphs = AcceptedRiskTags::taggedParagraphs([$path]);
        $this->assertCount(1, $paragraphs);
        $this->assertTrue(AcceptedRiskTags::paragraphSatisfiesFloor($paragraphs[0]));
    }

    #[Test]
    public function aTagAloneInItsOwnParagraphFailsTheContentFloor(): void
    {
        $path = $this->fixture('TagAloneFixture.php');
        $tags = AcceptedRiskTags::scanFile($path);

        $this->assertCount(1, $tags);
        $this->assertSame(501, $tags[0]->issueNumber);

        $paragraphs = AcceptedRiskTags::taggedParagraphs([$path]);
        $this->assertCount(1, $paragraphs);
        $this->assertFalse(
            AcceptedRiskTags::paragraphSatisfiesFloor($paragraphs[0]),
            'A tag with no accompanying rationale in its own paragraph must fail the content floor.',
        );
    }

    #[Test]
    public function grammarNearMissesAreReportedAsMalformedNotIgnored(): void
    {
        $tags = AcceptedRiskTags::scanFile($this->fixture('MalformedGrammarFixture.php'));

        $this->assertCount(3, $tags);

        foreach ($tags as $tag) {
            $this->assertNull($tag->issueNumber, "'{$tag->rawTag}' must not parse as a well-formed tag.");
        }

        $rawTags = \array_map(static fn (AcceptedRiskTag $tag): string => $tag->rawTag, $tags);
        $this->assertContains('@accepted-risk#123', $rawTags, 'Missing-space variant must be captured verbatim.');
        $this->assertContains('@accepted-risk #0', $rawTags, 'Leading-zero variant must be captured verbatim.');
        $this->assertContains('@accepted-risk #0123', $rawTags, 'Zero-padded variant must be captured verbatim.');
    }

    #[Test]
    public function aTagInsideAFencedCodeBlockIsIgnoredEntirely(): void
    {
        $tags = AcceptedRiskTags::scanFile($this->fixture('tag-in-fenced-code.md'));

        $this->assertSame([], $tags, 'A tag inside a fenced code block must never become a live dependency.');
    }

    #[Test]
    public function multipleTagsInOneParagraphAreAllIndependentlyValid(): void
    {
        $path = $this->fixture('MultipleTagsFixture.php');
        $tags = AcceptedRiskTags::scanFile($path);

        $this->assertCount(2, $tags);
        $this->assertSame([502, 503], \array_map(static fn (AcceptedRiskTag $tag): ?int => $tag->issueNumber, $tags));

        // One paragraph carries both tags -- the content floor is one obligation, not two.
        $paragraphs = AcceptedRiskTags::taggedParagraphs([$path]);
        $this->assertCount(1, $paragraphs);
        $this->assertTrue(AcceptedRiskTags::paragraphSatisfiesFloor($paragraphs[0]));
    }

    #[Test]
    public function theSameIssueTaggedInTwoFilesIsNotAStructuralViolation(): void
    {
        $paths = [$this->fixture('WellFormedTagFixture.php'), $this->fixture('same-issue-in-markdown.md')];
        $tags = AcceptedRiskTags::scanFiles($paths);

        $this->assertCount(2, $tags);
        $this->assertSame([500, 500], \array_map(static fn (AcceptedRiskTag $tag): ?int => $tag->issueNumber, $tags));

        foreach (AcceptedRiskTags::taggedParagraphs($paths) as $acceptedRiskParagraph) {
            $this->assertTrue(AcceptedRiskTags::paragraphSatisfiesFloor($acceptedRiskParagraph));
        }
    }

    /**
     * The grammar exists twice, by construction: {@see AcceptedRiskTags::strictIssueNumber()} in PHP, and a
     * literal `grep -oE` pattern in `.github/workflows/accepted-risk-live-state.yml` (bash has no way to
     * import the PHP one). Two independent hand-written expressions of one grammar, with nothing pinning
     * them together, is exactly the "two minters, no owner" shape this repo's own error-contract rules warn
     * against elsewhere -- a future change to either can drift from the other with every other gate green.
     * This test can't share code across languages, but it CAN pin the literal pattern text the workflow
     * uses, so an edit to either side that isn't mirrored in the other fails here rather than being
     * discovered as a live CI disagreement.
     */
    #[Test]
    public function theLiveStateWorkflowsBashGrammarTextHasNotDriftedFromThisGate(): void
    {
        $content = \file_get_contents($this->liveStateWorkflowPath());
        $this->assertNotFalse($content, 'Unable to read the live-state workflow.');

        $pattern = '@accepted-risk[[:space:]]+#[1-9][0-9]*';
        $needle = "grep -oE '{$pattern}'";
        $occurrences = \substr_count($content, $needle);

        $this->assertSame(
            2,
            $occurrences,
            "Expected the exact bash grammar `{$pattern}` twice in accepted-risk-live-state.yml (once per "
                . 'file-type branch of extract()) -- found ' . $occurrences . '. If the grammar changed on '
                . 'either side, update BOTH this literal and AcceptedRiskTags::strictIssueNumber() so they '
                . 'keep accepting/rejecting the same strings.',
        );
    }

    /**
     * Same repo-root resolution as {@see ScheduleConsumptionGateTest} -- an
     * unresolvable path fails rather than skipping.
     */
    private function liveStateWorkflowPath(): string
    {
        $apiRoot = \dirname(__DIR__, 3);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            $target = $candidate . '/.github/workflows/accepted-risk-live-state.yml';

            if (\is_file($target)) {
                return $target;
            }
        }

        $this->fail(
            'accepted-risk-live-state.yml is not reachable, so grammar parity cannot be checked. Inside the '
                . 'container it comes from the read-only `./` bind mount at /app/repo declared in '
                . 'compose.dev.yaml -- restore it rather than relaxing this failure into a skip.',
        );
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/Fixture/AcceptedRiskTags/' . $name;
    }
}
