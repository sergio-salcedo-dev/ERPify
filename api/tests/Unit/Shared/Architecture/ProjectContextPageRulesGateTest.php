<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextFixtureFiles;
use Erpify\Tests\Support\ProjectContextVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of what {@see ProjectContextVersionGateTest} reads off the PAGE: which of its words are
 * version claims, and which registry token covers one.
 *
 * This is the half that derives the universe, so its failure mode is the quietest of the three — an
 * extraction that stops finding claims does not report anything, it simply agrees with a registry that
 * binds nothing. The manifest side is {@see ProjectContextManifestRulesGateTest} and the registry side is
 * {@see ProjectContextRegistryRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextPageRulesGateTest extends TestCase
{
    use ProjectContextFixtureFiles;

    #[Test]
    public function everyVersionClaimInATableRowIsExtracted(): void
    {
        $page = <<<'MARKDOWN'
            | Concern | Technology (version) | Constraint |
            |---------|----------------------|------------|
            | ORM     | **Doctrine ORM 3.6**, DBAL 4.4 | Prose naming Behat 4 is not a claim |
            | Lint    | `eslint-config-next` 16.2      | —          |
            MARKDOWN;

        $this->assertSame(
            [
                ['claim' => 'ORM 3.6', 'line' => 3],
                ['claim' => 'DBAL 4.4', 'line' => 3],
                ['claim' => 'eslint-config-next 16.2', 'line' => 4],
            ],
            ProjectContextVersions::claimsIn($page),
        );
    }

    /**
     * The third column is prose and the first is a label. Reading either would make the universe
     * unmaintainable — prose names versions in passing — and the page would be gated on its narration.
     */
    #[Test]
    public function nothingOutsideTheSecondColumnOfATableIsAClaim(): void
    {
        $page = "Playwright 1.62 in a paragraph.\n\n| PHPUnit 13 | — | Behat 4 |\n";

        $this->assertSame([], ProjectContextVersions::claimsIn($page));
    }

    #[Test]
    #[DataProvider('provideARegistryTokenCoversTheClaimItEndsWithCases')]
    public function aRegistryTokenCoversTheClaimItEndsWith(string $token, string $claim, bool $covers): void
    {
        $this->assertSame($covers, ProjectContextVersions::coversClaim($token, $claim));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideARegistryTokenCoversTheClaimItEndsWithCases(): iterable
    {
        yield 'identical' => ['Behat 4', 'Behat 4', true];
        yield 'token spells the product out' => ['Doctrine ORM 3.6', 'ORM 3.6', true];
        // The boundary on the covering side. It must break on a word, not on any character: a bare
        // str_ends_with lets a DIFFERENT package cover the claim whenever its name ends with the
        // claimed one, and the gate then reports a version nobody bound.
        yield 'different package whose name ends with the claim' => ['happy-jsdom 30', 'jsdom 30', false];
        yield 'same product, different version' => ['Behat 4', 'Behat 5', false];
        yield 'claim is longer than the token' => ['ORM 3.6', 'Doctrine ORM 3.6', false];
    }

    /**
     * The direction a page edit breaks: the manifest still agrees, but nobody makes the claim any more.
     */
    #[Test]
    public function aTokenThatLeftThePageIsReportedEvenWhenTheManifestStillAgrees(): void
    {
        $root = $this->fixtureRoot(['require' => ['php' => '^8.5']]);

        $defect = ProjectContextVersions::defectIn(
            $root,
            $this->entry('manifest.json', 'require.php', 'PHP 8.5'),
            'a page that no longer mentions the runtime at all',
        );

        $this->assertIsString($defect);
        $this->assertStringContainsString('no longer appears', $defect);
    }

    /**
     * The half an exemption does NOT buy: a claim nobody makes any more still has to lose its line, or
     * the registry accumulates exemptions for versions the page stopped stating.
     */
    #[Test]
    public function anUnboundClaimThatLeftThePageIsStillReported(): void
    {
        $defect = ProjectContextVersions::defectIn(
            $this->fixtureRoot(['require' => ['php' => '^8.5']]),
            $this->entry(ProjectContextVersions::UNBOUND, 'pinned by digest in pwa/Dockerfile', 'Node 26'),
            'a page that no longer names the runtime',
        );

        $this->assertIsString($defect);
        $this->assertStringContainsString('no longer appears', $defect);
    }

    /**
     * The page writes `` `eslint-config-next` 16.2 ``, so the marker sits inside the claim. Comparing the
     * raw text made the extraction and the staleness check disagree, and no registry line satisfied both.
     */
    #[Test]
    public function aTokenIsFoundThroughThePageInlineEmphasis(): void
    {
        $this->assertNull(ProjectContextVersions::defectIn(
            $this->fixtureRoot(['devDependencies' => ['eslint-config-next' => '^16.2.10']]),
            $this->entry('manifest.json', 'devDependencies.eslint-config-next', 'eslint-config-next 16.2'),
            '| Lint | `eslint-config-next` 16.2 | — |',
        ));
    }
}
