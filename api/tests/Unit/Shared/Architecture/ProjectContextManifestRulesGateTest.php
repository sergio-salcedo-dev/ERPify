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
 * Falsifiability of what {@see ProjectContextVersionGateTest} asks of a MANIFEST: whether a declared
 * constraint satisfies a claimed version, and what a dotted path resolves to.
 *
 * Both rules are here because dropping either leaves the suite green over a page that lies. Without the
 * version boundary every single-digit claim (Behat 4, PHPStan 2, Rector 2, Inversify 8, TypeScript 6)
 * becomes unfalsifiable; without the operator allowlist a constraint written to *exclude* the claimed
 * version satisfies it. The page's side is {@see ProjectContextPageRulesGateTest} and the registry's is
 * {@see ProjectContextRegistryRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextManifestRulesGateTest extends TestCase
{
    use ProjectContextFixtureFiles;

    #[Test]
    #[DataProvider('provideADeclarationSatisfiesTheVersionItStartsWithCases')]
    public function aDeclarationSatisfiesTheVersionItStartsWith(string $declared, string $version): void
    {
        $this->assertTrue(ProjectContextVersions::satisfies($declared, $version));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideADeclarationSatisfiesTheVersionItStartsWithCases(): iterable
    {
        yield 'caret' => ['^8.5', '8.5'];
        yield 'wildcard, as Symfony Flex writes it' => ['8.1.*', '8.1'];
        yield 'stability flag, as behat/behat carries it' => ['^4.0@alpha', '4'];
        yield 'major claimed against a full patch constraint' => ['^13.3.0', '13'];
        yield 'exact' => ['3.9.6', '3.9'];
        yield 'tilde' => ['~0.2.2', '0.2'];
        yield 'inclusive lower bound, which is still a floor' => ['>=26.0.0', '26'];
    }

    #[Test]
    #[DataProvider('provideADeclarationDoesNotSatisfyAVersionItMerelyBeginsWithCases')]
    public function aDeclarationDoesNotSatisfyAVersionItMerelyBeginsWith(string $declared, string $version): void
    {
        $this->assertFalse(ProjectContextVersions::satisfies($declared, $version));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideADeclarationDoesNotSatisfyAVersionItMerelyBeginsWithCases(): iterable
    {
        // The one that matters: a bare prefix match would accept this, and every single-digit claim on
        // the page would then be unfalsifiable.
        yield 'digit that is a prefix but not a version' => ['^16.3.0', '1'];
        yield 'a different major' => ['^2.2.8', '3'];
        yield 'a different minor' => ['^4.3.2', '4.1'];
        yield 'claim longer than the declaration' => ['^8.5', '8.5.1'];
        // The permissive direction. Stripping the punctuation as a charlist inverted these: the page
        // could claim the one version its manifest is written to exclude, and the gate agreed.
        yield 'upper bound, which excludes the version it names' => ['<2.0', '2.0'];
        yield 'inclusive upper bound' => ['<=2.0', '2.0'];
        yield 'exclusive lower bound, which excludes the version it names' => ['>2', '2'];
        yield 'negation' => ['!=3.0', '3.0'];
        // A union has no single floor. Reading the first alternative is what blessed "Node 24" against
        // the manifest of a container running 26.
        yield 'union, first alternative' => ['^1.0.0 || ^2.0.0', '1.0'];
        yield 'union, as engines.node writes it' => ['^24.15.0 || >=26.0.0', '24'];
        yield 'comma-separated range' => ['>=1.0,<2.0', '1.0'];
        yield 'hyphenated range' => ['1.0 - 2.0', '1.0'];
    }

    #[Test]
    public function aDottedPathResolvesThroughNestedObjects(): void
    {
        $root = $this->fixtureRoot(['extra' => ['symfony' => ['require' => '8.1.*']]]);

        $this->assertSame('8.1.*', ProjectContextVersions::declaredConstraint(
            $root . '/manifest.json',
            'extra.symfony.require',
        ));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    #[Test]
    #[DataProvider('provideAnUnresolvablePathIsReportedRatherThanGuessedCases')]
    public function anUnresolvablePathIsReportedRatherThanGuessed(array $manifest, string $path): void
    {
        $root = $this->fixtureRoot($manifest);

        $this->assertNull(ProjectContextVersions::declaredConstraint($root . '/manifest.json', $path));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideAnUnresolvablePathIsReportedRatherThanGuessedCases(): iterable
    {
        yield 'absent key' => [['require' => ['php' => '^8.5']], 'require.absent'];
        yield 'path through a scalar' => [['require' => 'not-an-object'], 'require.php'];
        yield 'node is not a string' => [['require' => ['php' => ['^8.5']]], 'require.php'];
    }

    #[Test]
    public function anAbsentManifestIsNotReadAsAnEmptyConstraint(): void
    {
        $this->assertNull(ProjectContextVersions::declaredConstraint('/nonexistent/manifest.json', 'require.php'));
    }

    #[Test]
    public function invalidJsonIsNotReadAsAnEmptyConstraint(): void
    {
        $root = $this->temporaryDirectory();
        \file_put_contents($root . '/manifest.json', '{not json');

        $this->assertNull(ProjectContextVersions::declaredConstraint($root . '/manifest.json', 'require.php'));
    }
}
