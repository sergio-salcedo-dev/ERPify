<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\AcceptedRiskTags;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every real `@accepted-risk #<issue>` tag under `api/src` and the implementation-artifact specs must be
 * well-formed and co-located with enough prose to be more than a floating reference. See
 * {@see AcceptedRiskTagRulesGateTest} for the falsification of those rules against fixtures -- this test
 * only asserts them over the real tree, and asserts PROPERTIES rather than a tag count, because the
 * inventory legitimately grows.
 *
 * Zero network: whether a referenced issue is still OPEN is checked by an independent GitHub Actions job
 * (`.github/workflows/accepted-risk-live-state.yml`), never here.
 *
 * @internal test support
 */
#[CoversNothing]
final class AcceptedRiskTagGateTest extends TestCase
{
    #[Test]
    public function everyTagInTheApiSourceTreeIsWellFormedAndMeetsTheContentFloor(): void
    {
        $this->assertEveryTagValid($this->phpSourceFiles());
    }

    #[Test]
    public function everyTagInTheImplementationArtifactSpecsIsWellFormedAndMeetsTheContentFloor(): void
    {
        $this->assertEveryTagValid($this->specFiles());
    }

    /**
     * Collects every violation across every file before asserting, rather than failing on the first one:
     * per docs/rules/testing.md, a rule this gate is meant to enforce should have every one of its reds
     * provoked and reported together, not discovered one PHPUnit run at a time. Iterating file-by-file
     * (instead of {@see AcceptedRiskTags::scanFiles()}/{@see AcceptedRiskTags::taggedParagraphs()} once
     * over the whole set) also keeps each floor violation attributed to the file it came from --
     * {@see AcceptedRiskParagraph} carries no file identity of its own.
     *
     * @param list<string> $files
     */
    private function assertEveryTagValid(array $files): void
    {
        $malformed = [];
        $floorViolations = [];

        foreach ($files as $file) {
            foreach (AcceptedRiskTags::scanFile($file) as $acceptedRiskTag) {
                if (null === $acceptedRiskTag->issueNumber) {
                    $where = "{$acceptedRiskTag->sourceFile}:{$acceptedRiskTag->line}";
                    $malformed[] = "'{$acceptedRiskTag->rawTag}' at {$where}";
                }
            }

            foreach (AcceptedRiskTags::taggedParagraphs([$file]) as $acceptedRiskParagraph) {
                if (!AcceptedRiskTags::paragraphSatisfiesFloor($acceptedRiskParagraph)) {
                    $span = "{$acceptedRiskParagraph->startLine}-{$acceptedRiskParagraph->endLine}";
                    $floorViolations[] = "{$file}:{$span}";
                }
            }
        }

        $this->assertSame(
            [],
            $malformed,
            "Malformed accepted-risk tag(s) -- expected the exact grammar `@accepted-risk #<issue>`:\n"
                . \implode("\n", $malformed),
        );
        $this->assertSame(
            [],
            $floorViolations,
            'Accepted-risk tag paragraph(s) with no accompanying rationale -- co-locate the tag with the '
                . "disposition text, not alone:\n" . \implode("\n", $floorViolations),
        );
    }

    /**
     * @return list<string>
     */
    private function phpSourceFiles(): array
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $this->assertInstanceOf(SplFileInfo::class, $file);

            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function specFiles(): array
    {
        $files = \glob($this->implementationArtifactsDirectory() . '/spec-*.md');

        return false === $files ? [] : $files;
    }

    /**
     * Where the implementation-artifact specs are reachable from, which differs by how the suite is
     * invoked: with the whole checkout present they sit beside `api/`, while inside the dev container
     * `/app` holds only `api/`, `public/` and the mounts, so they arrive through the read-only root bind
     * mount declared in `compose.dev.yaml` -- the same resolution {@see ScheduleConsumptionGateTest} uses
     * for the root compose files.
     *
     * An unresolvable directory FAILS rather than skipping -- a check that quietly does nothing when its
     * input is absent reports the same green as a real pass.
     */
    private function implementationArtifactsDirectory(): string
    {
        $apiRoot = \dirname(__DIR__, 3);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            $target = $candidate . '/_bmad-output/implementation-artifacts';

            if (\is_dir($target)) {
                return $target;
            }
        }

        $this->fail(
            'The implementation-artifacts directory is not reachable, so this gate cannot check spec files. '
            . 'Inside the container it comes from the read-only `./` bind mount at /app/repo declared in '
            . 'compose.dev.yaml -- restore it rather than relaxing this failure into a skip.',
        );
    }
}
