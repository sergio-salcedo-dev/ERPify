<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the rules behind {@see ProjectContextVersionGateTest}.
 *
 * This half pins the comparison: a declaration satisfies a claim only at a version boundary, and an
 * unresolvable path is reported rather than guessed. Without the boundary the check accepts any prefix,
 * so every single-digit claim on the page — Behat 4, PHPStan 2, Rector 2, Inversify 8, TypeScript 6 —
 * becomes unfalsifiable while the suite stays green. The registry half is
 * {@see ProjectContextRegistryRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextVersionRulesGateTest extends TestCase
{
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

    /**
     * @param array<string, mixed> $manifest
     */
    private function fixtureRoot(array $manifest): string
    {
        $root = $this->temporaryDirectory();
        \file_put_contents(
            $root . '/manifest.json',
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        );

        return $root;
    }

    private function temporaryDirectory(): string
    {
        $root = \sys_get_temp_dir() . '/project-context-versions-' . \bin2hex(\random_bytes(8));
        $this->assertTrue(\mkdir($root, 0o777, true));

        return $root;
    }
}
