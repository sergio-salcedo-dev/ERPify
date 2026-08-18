<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the registry half of {@see ProjectContextVersionGateTest}: how a line is parsed, and
 * how each way an entry can stop holding is reported.
 *
 * The three defects are asserted apart on purpose. "The manifest moved", "the page reworded the claim" and
 * "this line addresses nothing" have different fixes, and a gate that collapsed them into one message would
 * send the reader to the wrong file. The staleness direction matters most: it is the one a page edit
 * breaks while every manifest still agrees, so nothing else in the build would notice.
 *
 * A malformed line throws rather than being skipped. Skipping is the failure mode that matters here —
 * a registry that silently drops what it cannot parse shrinks to nothing one typo at a time, reporting
 * green the whole way down. The comparison half is {@see ProjectContextVersionRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextRegistryRulesGateTest extends TestCase
{
    #[Test]
    public function anEntryThatHoldsInBothDirectionsReportsNoDefect(): void
    {
        $root = $this->fixtureRoot(['require' => ['php' => '^8.5']]);

        $this->assertNull(ProjectContextVersions::defectIn(
            $root,
            $this->entry('manifest.json', 'require.php', 'PHP 8.5'),
            'the page says PHP 8.5 somewhere',
        ));
    }

    #[Test]
    public function aDriftedVersionIsReportedAgainstTheManifest(): void
    {
        $root = $this->fixtureRoot(['require' => ['php' => '^8.4']]);

        $defect = ProjectContextVersions::defectIn(
            $root,
            $this->entry('manifest.json', 'require.php', 'PHP 8.5'),
            'the page says PHP 8.5 somewhere',
        );

        $this->assertIsString($defect);
        $this->assertStringContainsString('^8.4', $defect);
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

    #[Test]
    public function aPathAddressingNothingIsReportedApartFromDrift(): void
    {
        $root = $this->fixtureRoot(['require' => ['php' => '^8.5']]);

        $defect = ProjectContextVersions::defectIn(
            $root,
            $this->entry('manifest.json', 'require.absent', 'PHP 8.5'),
            'the page says PHP 8.5 somewhere',
        );

        $this->assertIsString($defect);
        $this->assertStringContainsString('addresses nothing', $defect);
    }

    #[Test]
    public function commentsAndBlankLinesAreNotEntries(): void
    {
        $registry = $this->registryHolding(
            "# a comment\n\napi/composer.json :: require.php => PHP 8.5\n\n#another\n",
        );

        $entries = ProjectContextVersions::entriesIn($registry);

        $this->assertCount(1, $entries);
        $this->assertSame('PHP 8.5', $entries[0]['token']);
        $this->assertSame('8.5', $entries[0]['version']);
        $this->assertSame('api/composer.json', $entries[0]['manifest']);
        $this->assertSame('require.php', $entries[0]['path']);
    }

    #[Test]
    public function aMalformedLineIsRefusedRatherThanSkipped(): void
    {
        $registry = $this->registryHolding("api/composer.json :: require.php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('is not "<manifest> :: <path> => <token>"');

        ProjectContextVersions::entriesIn($registry);
    }

    #[Test]
    public function aLineNamingNoManifestIsRefusedRatherThanSkipped(): void
    {
        $registry = $this->registryHolding("require.php => PHP 8.5\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('names no "<manifest> :: <path>"');

        ProjectContextVersions::entriesIn($registry);
    }

    #[Test]
    public function anUnreadableRegistryIsRefusedRatherThanReadAsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('unreadable');

        ProjectContextVersions::entriesIn('/nonexistent/.project-context-versions');
    }

    /**
     * @return array{manifest: string, path: string, token: string, version: string}
     */
    private function entry(string $manifest, string $path, string $token): array
    {
        $words = \explode(' ', $token);

        return [
            'manifest' => $manifest,
            'path' => $path,
            'token' => $token,
            'version' => $words[\count($words) - 1],
        ];
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

    private function registryHolding(string $contents): string
    {
        $path = $this->temporaryDirectory() . '/.project-context-versions';
        \file_put_contents($path, $contents);

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $root = \sys_get_temp_dir() . '/project-context-versions-' . \bin2hex(\random_bytes(8));
        $this->assertTrue(\mkdir($root, 0o777, true));

        return $root;
    }
}
