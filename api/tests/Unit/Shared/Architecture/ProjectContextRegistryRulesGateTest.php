<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ProjectContextFixtureFiles;
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
 * green the whole way down.
 *
 * An `unbound` line is exempt from the manifest half and from nothing else. It exists so a claim nothing
 * can own is *declared* rather than omitted, and an omission and an exemption must not look alike — which
 * is why the staleness direction still applies to it, in {@see ProjectContextPageRulesGateTest}. What a
 * constraint satisfies is {@see ProjectContextManifestRulesGateTest}.
 *
 * @internal
 */
#[CoversNothing]
final class ProjectContextRegistryRulesGateTest extends TestCase
{
    use ProjectContextFixtureFiles;

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

    /**
     * The reason is not a dotted path and is never resolved. Reading it as one would report "addresses
     * nothing" over every exemption, which is the opposite of what the line is for.
     */
    #[Test]
    public function anUnboundClaimIsNotCheckedAgainstAnyManifest(): void
    {
        $root = $this->fixtureRoot(['require' => ['php' => '^8.5']]);

        $this->assertNull(ProjectContextVersions::defectIn(
            $root,
            $this->entry(ProjectContextVersions::UNBOUND, 'pinned by digest in pwa/Dockerfile', 'Node 26'),
            'the page says Node 26 somewhere',
        ));
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
}
