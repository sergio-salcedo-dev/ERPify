<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\AcceptanceEntryPoints;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\CredentialProofPolicy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Static gate over what the acceptance lane may credit with coverage, and over the run CI gates on.
 *
 * `api/behat.dist.php`'s `coverage` profile reports only the files {@see AcceptanceEntryPoints} derives, and
 * CI runs that profile as the merge gate. Three things keep that honest, and each fails on its own:
 *
 *   (1) The profile feeds the collector the derived list and nothing wider. A `directories` entry would credit
 *       every class a scenario traverses, which SonarCloud then counts as covered whatever PHPUnit's
 *       attributed report says (it merges the two per line by logical OR).
 *   (2) The derivation finds every entry point, read by two instruments the derivation does not share: the
 *       production router inventory (`.route-manifest.json`, held equal to the prod router by
 *       `php.lint.route-manifest`), each route resolved to its controller by reflecting `#[Route]`; and a
 *       plain text sweep for the two attributes, which catches a derivation that silently drops a class
 *       (an autoload miss, a broken `class_exists`) as well as one that admits a class carrying neither.
 *   (3) The profile overrides no tester option and declares no filter. It inherits strict result
 *       interpretation and the whole suite from `default`, which {@see BehatSuiteCoverageGateTest} pins;
 *       an override here would relax the very run CI gates on while that gate stayed green.
 *
 * **What a green does not prove.** That an entry point's behaviour is asserted — a scenario that only checks a
 * status code still credits its controller. That a route declared outside `#[Route]` (YAML, PHP routing
 * files) is admitted: no prod route is today, which (2) asserts, but a dev-only one is
 * (`WebDebugToolbarLoaderController`) and stays uncredited. Anything about Sonar's own merge.
 *
 * @internal
 */
#[CoversNothing]
final class BehatCoverageScopeGateTest extends TestCase
{
    public const string FAILURE_PREAMBLE
        = 'Behat coverage must credit entry points only, and its profile must not relax the gate. '
        . 'See api/behat.dist.php and tests/Support/AcceptanceEntryPoints.php';

    private const string CONFIG_PATH = 'behat.dist.php';

    private const string PROFILE_MARKER = "new Profile('coverage')";

    private const string DERIVED_FILTER = "'files' => AcceptanceEntryPoints::files(),";

    public function testTheCoverageProfileReportsTheDerivedEntryPointsAndNothingWider(): void
    {
        $profile = $this->coverageProfile();

        $this->assertStringContainsString(
            self::DERIVED_FILTER,
            $profile,
            self::FAILURE_PREAMBLE . "\nThe coverage profile no longer feeds the collector the derived entry-point "
            . 'list, so what the acceptance lane credits is no longer decided by AcceptanceEntryPoints.',
        );
        $this->assertStringNotContainsString(
            "'directories'",
            $profile,
            self::FAILURE_PREAMBLE . "\nThe coverage profile includes a directory. Every class a scenario merely "
            . 'traverses in it would count as covered in Sonar, attribution or not.',
        );
    }

    public function testEveryProductionRouteResolvesToADerivedEntryPoint(): void
    {
        $policy = CredentialProofPolicy::fromApiRoot($this->apiRoot());
        $actions = $policy->actionsByRoute();
        $entryPoints = AcceptanceEntryPoints::files();
        $violations = [];

        foreach (\array_keys($policy->routePaths()) as $route) {
            if (!isset($actions[$route])) {
                $violations[] = \sprintf('%s resolves to no #[Route] under src/', $route);

                continue;
            }

            foreach ($actions[$route] as $action) {
                $file = (new ReflectionClass($action['class']))->getFileName();

                if (!\in_array($file, $entryPoints, true)) {
                    $violations[] = \sprintf('%s is served by %s, which is not in the list', $route, $action['class']);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            self::FAILURE_PREAMBLE . "\nProduction routes the coverage filter would not credit:\n"
            . \implode("\n", $violations),
        );
    }

    public function testTheDerivationAgreesWithATextSweepForTheTwoAttributes(): void
    {
        $byText = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = (string) \file_get_contents($file->getPathname());

            if (\str_contains($source, '#[Route(') || \str_contains($source, '#[AsCommand(')) {
                $byText[] = (string) $file->getRealPath();
            }
        }

        \sort($byText);

        $this->assertNotSame([], $byText, self::FAILURE_PREAMBLE . "\nThe text sweep found no entry point at all.");
        $this->assertSame(
            $byText,
            AcceptanceEntryPoints::files(),
            self::FAILURE_PREAMBLE . "\nThe reflected entry points disagree with the files that spell #[Route( or "
            . '#[AsCommand(. Either the derivation drops a class it should credit, or it admits one carrying '
            . 'neither attribute.',
        );
    }

    public function testTheCoverageProfileOverridesNoTesterOptionAndDeclaresNoFilter(): void
    {
        $profile = $this->coverageProfile();

        foreach (['withTesterOptions(', 'withFilter(', 'withSuite('] as $override) {
            $this->assertStringNotContainsString(
                $override,
                $profile,
                \sprintf(
                    "%s\nThe coverage profile calls %s. CI gates on this profile, so an override here — a relaxed "
                    . 'result interpretation, a tag filter, a narrower suite — weakens the merge gate while the '
                    . 'default profile, and every gate reading it, stays green.',
                    self::FAILURE_PREAMBLE,
                    $override,
                ),
            );
        }
    }

    /**
     * Everything from the coverage profile's declaration to the end of the file: it is the last profile, so
     * this slice holds its whole body and nothing of `default`'s.
     */
    private function coverageProfile(): string
    {
        $config = (string) \file_get_contents($this->apiRoot() . '/' . self::CONFIG_PATH);
        $start = \strpos($config, self::PROFILE_MARKER);

        $this->assertNotFalse(
            $start,
            self::FAILURE_PREAMBLE . "\nNo coverage profile in " . self::CONFIG_PATH . ' — this gate reads nothing.',
        );

        return \substr($config, $start);
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
